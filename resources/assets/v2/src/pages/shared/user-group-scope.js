const userGroupParameter = 'user_group_id';

function positiveInteger(value) {
    const normalized = String(value ?? '').trim();
    if (!/^[1-9][0-9]*$/.test(normalized)) {
        return 0;
    }
    const parsed = Number(normalized);

    return Number.isSafeInteger(parsed) ? parsed : 0;
}

export function selectedUserGroupId() {
    const params = new URLSearchParams(window.location.search);

    return positiveInteger(params.get(userGroupParameter));
}

export function scopedParams(params = {}, userGroupId = selectedUserGroupId()) {
    if (userGroupId <= 0 || Object.prototype.hasOwnProperty.call(params, userGroupParameter)) {
        return params;
    }

    return {
        ...params,
        [userGroupParameter]: userGroupId,
    };
}

export function scopedUrl(url, userGroupId = selectedUserGroupId()) {
    if (userGroupId <= 0 || typeof url !== 'string') {
        return url;
    }

    const isAbsolute = /^https?:\/\//.test(url);
    const isRootRelative = url.startsWith('/');
    const relativePrefix = url.startsWith('./') ? './' : '';
    const parsed = new URL(url, window.location.origin);

    if (!parsed.searchParams.has(userGroupParameter)) {
        parsed.searchParams.set(userGroupParameter, userGroupId);
    }

    if (isAbsolute) {
        return parsed.href;
    }

    if (isRootRelative) {
        return parsed.pathname + parsed.search + parsed.hash;
    }

    return relativePrefix + parsed.pathname.replace(/^\//, '') + parsed.search + parsed.hash;
}
