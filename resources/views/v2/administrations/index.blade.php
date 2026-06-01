@extends('layout.v2')
@section('scripts')
    @vite(['src/pages/administrations/index.js'])
@endsection
@section('content')
    <div class="app-content">
        <div class="container-fluid" x-data="index">
            <x-messages></x-messages>
            <template x-if="notifications.error.show">
                <div class="alert alert-danger" x-text="notifications.error.text"></div>
            </template>
            <template x-if="notifications.wait.show">
                <div class="alert alert-info" x-text="notifications.wait.text"></div>
            </template>
            <div class="row mb-3">
                <div class="col-xl-8 col-lg-10 col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Administration context</h3>
                        </div>
                        <div class="card-body">
                            <template x-if="currentAdministration">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-5">
                                        <strong x-text="currentAdministration.title"></strong><br>
                                        <span class="text-muted" x-text="currentAdministration.you"></span>
                                    </div>
                                    <div class="col-md-5">
                                        <label for="administration-switcher" class="form-label">Switch administration</label>
                                        <select id="administration-switcher" class="form-select" x-model.number="selectedAdministrationId">
                                            <template x-for="group in userGroups" :key="'switch-' + group.id">
                                                <option :value="group.id" x-text="group.title"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button class="btn btn-primary w-100" @click="switchAdministration()" :disabled="selectedAdministrationId === currentAdministration.id">
                                            <em class="fa-solid fa-repeat"></em> Use
                                        </button>
                                    </div>
                                </div>
                            </template>
                            <template x-if="!currentAdministration && !notifications.wait.show">
                                <p class="mb-0">No administration is currently selected.</p>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col">
                    <p>
                        <a href="{{route('administrations.create')}}"
                           class="btn btn-primary">{{ __('firefly.create_administration') }}</a>
                    </p>
                </div>
            </div>
            <template x-if="0 === userGroups.length && !notifications.wait.show">
                <div class="row mb-3">
                    <div class="col-xl-6 col-lg-8 col-md-12">
                        <div class="card">
                            <div class="card-body">
                                <p class="mb-0">No administrations are available.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
            <div class="row mb-3">
                <template x-for="(group, index) in userGroups" :key="index">
                    <div class="col-xl-4 col-lg-4 col-sm-6 col-xs-12 mb-3">
                        <div :class="{'card': true, 'card-primary': group.in_use}">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <template x-if="group.in_use">
                                        <em class="fa-regular fa-square-check"></em>
                                    </template>
                                    Administration "<span x-text="group.title"></span>"</h3>
                            </div>
                            <div class="card-body">
                                <ul>
                                    <template x-if="'' !== group.owner">
                                        <li x-text="group.owner"></li>
                                    </template>
                                    <template x-if="'' !== group.you">
                                        <li x-text="group.you"></li>
                                    </template>
                                </ul>
                                <template x-if="group.memberCountExceptYou > 0">
                                    <div>
                                        <h5>{{ __('firefly.other_users_in_admin') }}</h5>
                                        <ul>
                                            <template x-for="(member, jndex) in group.members" :key="jndex">
                                                <li>
                                                    <span x-text="member.email"></span>
                                                    <span class="text-muted" x-text="'(' + member.roles.join(', ') + ')'"></span>
                                                </li>
                                            </template>
                                        </ul>
                                    </div>
                                </template>
                                <template x-if="group.membersVisible && 0 === group.memberCountExceptYou">
                                    <p class="mb-0 text-muted">No other members have access.</p>
                                </template>
                                <template x-if="!group.membersVisible">
                                    <p class="mb-0 text-muted">You do not have permission to view or change membership.</p>
                                </template>
                                <div class="mt-3">
                                    <div class="btn-group" x-show="group.canRead">
                                        <a class="btn btn-outline-primary btn-sm" :href="scopedUrl('{{ route('accounts.index', ['asset']) }}', group.id)">
                                            <em class="fa-solid fa-wallet"></em> Accounts
                                        </a>
                                        <a class="btn btn-outline-primary btn-sm" :href="scopedUrl('{{ route('transactions.index', ['all']) }}', group.id)">
                                            <em class="fa-solid fa-list"></em> Transactions
                                        </a>
                                    </div>
                                </div>
                                <template x-if="group.canManageMembers">
                                    <div class="mt-3 border-top pt-3">
                                        <h5>Access rights</h5>
                                        <template x-if="membershipForms[group.id]">
                                            <div>
                                                <div class="mb-2">
                                                    <label class="form-label">User email</label>
                                                    <input class="form-control" type="email" x-model="membershipForms[group.id].email" :disabled="membershipForms[group.id].id">
                                                </div>
                                                <div class="mb-2">
                                                    <template x-for="option in roleOptions" :key="group.id + '-' + option.value">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" :id="'role-' + group.id + '-' + option.value" :value="option.value" x-model="membershipForms[group.id].roles" :disabled="!canUseRole(group, option.value)">
                                                            <label class="form-check-label" :for="'role-' + group.id + '-' + option.value" x-text="option.label"></label>
                                                        </div>
                                                    </template>
                                                </div>
                                                <div class="btn-group">
                                                    <button class="btn btn-primary btn-sm" @click="saveMembership(group)">
                                                        <em class="fa-regular fa-circle-check"></em> Save
                                                    </button>
                                                    <button class="btn btn-secondary btn-sm" @click="cancelMembership(group)">
                                                        <em class="fa-solid fa-ban"></em> Cancel
                                                    </button>
                                                </div>
                                            </div>
                                        </template>
                                        <template x-if="!membershipForms[group.id]">
                                            <div>
                                                <button class="btn btn-primary btn-sm mb-2" @click="addMember(group)">
                                                    <em class="fa-solid fa-user-plus"></em> Add member
                                                </button>
                                                <template x-if="group.memberCountExceptYou > 0">
                                                    <div class="table-responsive">
                                                        <table class="table table-sm">
                                                            <tbody>
                                                            <template x-for="member in group.members" :key="'member-' + group.id + '-' + member.id">
                                                                <tr>
                                                                    <td x-text="member.email"></td>
                                                                    <td x-text="member.roles.join(', ')"></td>
                                                                    <td class="text-end">
                                                                        <div class="btn-group">
                                                                            <button class="btn btn-outline-primary btn-sm" @click="editMember(group, member)" :disabled="!canEditMember(group, member)">
                                                                                <em class="fa-solid fa-pencil"></em>
                                                                            </button>
                                                                            <button class="btn btn-outline-danger btn-sm" @click="removeMember(group, member)" :disabled="!canEditMember(group, member)">
                                                                                <em class="fa-solid fa-trash"></em>
                                                                            </button>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            </template>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                            <div class="card-footer">
                                <div class="btn-group">
                                    <template x-if="false === group.in_use">
                                    <button @click="useAdministration(group.id)" class="btn btn-primary" :disabled="!group.canUse">
                                        <em class="fa-solid fa-coins"></em> Use
                                    </button>
                                    </template>
                                    <template x-if="true === group.canManageMembers">
                                    <a :href="'{{route('administrations.edit', [''])}}/' + group.id" class="btn btn-primary">
                                        <em class="fa-solid fa-pencil"></em> {{ __('firefly.edit') }}
                                    </a>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            <div class="row mb-3">
                <div class="col">
                    <p>
                        <a href="{{route('administrations.create')}}"
                           class="btn btn-primary">{{ __('firefly.create_administration') }}</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

@endsection
