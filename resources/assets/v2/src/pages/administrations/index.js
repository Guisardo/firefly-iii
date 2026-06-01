/*
 * show.js
 * Copyright (c) 2024 james@firefly-iii.org.
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see https://www.gnu.org/licenses/.
 */

import '../../boot/bootstrap.js';
import dates from "../shared/dates.js";
import i18next from "i18next";
import {format} from "date-fns";

import '../../css/grid-ff3-theme.css';
import Get from "../../api/v1/model/user-group/get.js";
import Post from "../../api/v1/model/user-group/post.js";
import Put from "../../api/v1/model/user-group/put.js";

let index = function () {
    return {
        // notifications
        notifications: {
            error: {
                show: false, text: '', url: '',
            }, success: {
                show: false, text: '', url: '',
            }, wait: {
                show: false, text: '',

            }
        },
        editors: {},
        userGroups: [],
        currentAdministration: null,
        selectedAdministrationId: 0,
        roleOptions: [
            {value: 'ro', label: 'Read only'},
            {value: 'mng_trx', label: 'Manage transactions'},
            {value: 'full', label: 'Full access'},
            {value: 'owner', label: 'Owner'},
        ],
        membershipForms: {},

        format(date) {
            return format(date, i18next.t('config.date_time_fns'));
        },

        init() {
            this.notifications.wait.show = true;
            this.notifications.wait.text = i18next.t('firefly.wait_loading_data')
            this.loadAdministrations();
        },
        useAdministration(id) {
            let groupId = parseInt(id);
            // try to post "use", then reload administrations.
            (new Post()).use(groupId).then(response => {
               this.loadAdministrations();
            }).catch(error => {
                this.showError(error);
            });
        },
        switchAdministration() {
            if (this.selectedAdministrationId > 0) {
                this.useAdministration(this.selectedAdministrationId);
            }
        },
        scopedUrl(url, groupId) {
            return url + '?user_group_id=' + parseInt(groupId);
        },
        editMember(group, member) {
            this.membershipForms[group.id] = {
                id: member.id,
                email: member.email,
                roles: [...member.rawRoles],
            };
        },
        addMember(group) {
            this.membershipForms[group.id] = {
                id: null,
                email: '',
                roles: ['ro'],
            };
        },
        cancelMembership(group) {
            this.membershipForms[group.id] = null;
        },
        saveMembership(group) {
            let form = this.membershipForms[group.id];
            if (!form || (!form.id && '' === form.email)) {
                return;
            }

            let submission = {
                roles: form.roles,
            };
            if (form.id) {
                submission.id = form.id;
            }
            if (!form.id) {
                submission.email = form.email;
            }

            this.notifications.wait.show = true;
            this.notifications.wait.text = i18next.t('firefly.wait_loading_data');
            (new Put()).updateMembership(group.id, submission).then(() => {
                this.membershipForms[group.id] = null;
                this.loadAdministrations();
            }).catch(error => {
                this.notifications.wait.show = false;
                this.showError(error);
            });
        },
        removeMember(group, member) {
            if (!this.canEditMember(group, member)) {
                return;
            }

            this.membershipForms[group.id] = null;
            this.notifications.wait.show = true;
            this.notifications.wait.text = i18next.t('firefly.wait_loading_data');
            (new Put()).updateMembership(group.id, {id: member.id, roles: []}).then(() => {
                this.loadAdministrations();
            }).catch(error => {
                this.notifications.wait.show = false;
                this.showError(error);
            });
        },
        canUseRole(group, role) {
            if ('owner' === role) {
                return group.isOwner;
            }

            return group.canManageMembers;
        },
        canEditMember(group, member) {
            return group.isOwner || !member.rawRoles.includes('owner');
        },
        roleLabel(role) {
            let translated = i18next.t('firefly.administration_role_' + role);
            if ('firefly.administration_role_' + role !== translated) {
                return translated;
            }

            let option = this.roleOptions.find((current) => current.value === role);
            return option ? option.label : role;
        },
        showError(error) {
            this.notifications.error.show = true;
            this.notifications.error.text = error.response?.data?.message ?? 'The administration change was denied.';
        },

        loadAdministrations() {
            this.userGroups = [];
            this.currentAdministration = null;
            this.notifications.wait.show = true;
            this.notifications.wait.text = i18next.t('firefly.wait_loading_data')
            this.accounts = [];
            (new Get()).index({page: this.page}).then(response => {
                for (let i = 0; i < response.data.data.length; i++) {
                    if (response.data.data.hasOwnProperty(i)) {
                        let current = response.data.data[i];
                        let group = {
                            id: parseInt(current.id),
                            title: current.attributes.title,
                            in_use: current.attributes.in_use,
                            owner: '',
                            you: '',
                            memberCountExceptYou: 0,
                            isOwner: false,
                            canManageMembers: false,
                            membersVisible: current.attributes.can_see_members,
                            members: [],
                        };
                        let memberships = {};
                        for (let j = 0; j < current.attributes.members.length; j++) {
                            let member = current.attributes.members[j];
                            let roles = member.roles ?? (member.role ? [member.role] : []);
                            if (roles.includes('owner')) {
                                group.owner = i18next.t('firefly.administration_owner', {email: member.user_email});
                            }
                            if (true === member.you && roles.includes('owner')) {
                                group.isOwner = true;
                            }
                            if (true === member.you) {
                                group.canManageMembers = roles.includes('owner') || roles.includes('full');
                                group.you = i18next.t('firefly.administration_you', {role: roles.map((role) => this.roleLabel(role)).join(', ')});
                            }
                            if (false === member.you) {
                                group.memberCountExceptYou++;
                                const userEmail = member.user_email;
                                if (!memberships.hasOwnProperty(userEmail)) {
                                    memberships[userEmail] = {
                                        id: parseInt(member.user_id),
                                        email: userEmail,
                                        roles: [],
                                        rawRoles: [],
                                        isOwner: false,
                                    };
                                }
                                for (let k = 0; k < roles.length; k++) {
                                    memberships[userEmail].roles.push(this.roleLabel(roles[k]));
                                    memberships[userEmail].rawRoles.push(roles[k]);
                                }
                                memberships[userEmail].isOwner = memberships[userEmail].rawRoles.includes('owner');
                            }
                        }
                        group.members = Object.values(memberships);
                        this.membershipForms[group.id] ??= null;

                        this.userGroups.push(group);
                        if (group.in_use) {
                            this.currentAdministration = group;
                            this.selectedAdministrationId = group.id;
                        }
                    }
                }
                this.notifications.wait.show = false;
                // add click trigger thing.
            }).catch(error => {
                this.notifications.wait.show = false;
                this.showError(error);
            });
        },
    }
}

let comps = {index, dates};

function loadPage() {
    Object.keys(comps).forEach(comp => {
        console.log(`Loading page component "${comp}"`);
        let data = comps[comp]();
        Alpine.data(comp, () => data);
    });
    Alpine.start();
}

// wait for load until bootstrapped event is received.
document.addEventListener('firefly-iii-bootstrapped', () => {
    console.log('Loaded through event listener.');
    loadPage();
});
// or is bootstrapped before event is triggered.
if (window.bootstrapped) {
    console.log('Loaded through window variable.');
    loadPage();
}
