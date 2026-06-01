@php
    $resolvedUserGroupId = (int) ($userGroupId ?? request()->integer('user_group_id'));
@endphp
<script nonce="{{ $JS_NONCE }}">
    window.fireflyPageState = Object.assign({}, window.fireflyPageState || {}, {
        userGroupId: {{ $resolvedUserGroupId }}
    });
    window.userGroupId = {{ $resolvedUserGroupId }};
</script>
@yield('scripts')
