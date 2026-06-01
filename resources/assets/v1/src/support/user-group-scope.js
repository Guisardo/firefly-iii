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
    const pageUserGroupId = positiveInteger(window.userGroupId ?? 0);
    if (pageUserGroupId > 0) {
        return pageUserGroupId;
    }

    const queryParams = new URLSearchParams(window.location.search);

    return positiveInteger(queryParams.get(userGroupParameter));
}

export function scopedUrl(url, userGroupId = selectedUserGroupId()) {
    if (userGroupId <= 0 || typeof url !== 'string' || !url.includes('api/v1/')) {
        return url;
    }

    const parsed = new URL(url, document.getElementsByTagName('base')[0].href);
    if (!parsed.searchParams.has(userGroupParameter)) {
        parsed.searchParams.set(userGroupParameter, userGroupId);
    }

    return parsed.href;
}
