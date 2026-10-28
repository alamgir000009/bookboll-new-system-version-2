<?php

namespace App\Support;

use App\Models\Community;
use App\Models\CommunityMember;

/**
 * Who may do what inside a community.
 *
 * One authority, because the frontend already makes these decisions to decide
 * which controls to show (web/src/lib/communityAccess.js). If the server
 * answers differently, a member sees a compose box that rejects them, or a
 * moderator is handed a permissions panel that 403s. The predicates here are
 * the same predicates, deliberately.
 *
 * Hiding a control was never the boundary; this is.
 */
class CommunityPermissions
{
    /** Highest first. A role further up may do everything a role below may. */
    public const ROLES = ['owner', 'admin', 'moderator', 'member'];

    /** Who may contribute to a channel. */
    public const AUDIENCES = ['members', 'moderators', 'admins'];

    public const DEFAULTS = [
        'post_permission' => 'members',
        'chat_permission' => 'members',
        'chat_locked'     => false,
    ];

    /** The only keys a moderator's narrow patch may carry. */
    public const MODERATOR_SETTINGS = ['chat_permission', 'chat_locked'];

    /**
     * Settings as explicit values, always.
     *
     * The document requires responses to state the permissions rather than
     * leaving them absent, because an absent value is indistinguishable from a
     * server that dropped the setting — which is exactly the bug the frontend
     * read-back check exists to catch.
     *
     * `chat_locked` stays a compatibility field: on a row saved before explicit
     * permissions existed, a locked chat means admins only.
     */
    public static function settings(?Community $community): array
    {
        $raw = is_array($community?->settings) ? $community->settings : [];

        $post = self::audience($raw['post_permission'] ?? null);
        $chat = self::audience($raw['chat_permission'] ?? null);

        if ($chat === null) {
            // Legacy row: the announcements switch is the only thing it said.
            $chat = ! empty($raw['chat_locked']) ? 'admins' : self::DEFAULTS['chat_permission'];
        }

        $post ??= self::DEFAULTS['post_permission'];

        // Every other key the community carries is preserved untouched — custom
        // settings are not ours to drop.
        return array_merge($raw, [
            'post_permission' => $post,
            'chat_permission' => $chat,
            // Kept in step so an older client reading only this field still
            // sees the truth rather than a stale value.
            'chat_locked'     => $chat === 'admins',
        ]);
    }

    /**
     * Merge a settings patch over what is stored, validating the enums.
     *
     * Merge, never replace: a patch carrying only `chat_permission` must not
     * erase the feed permission or anything else saved alongside it.
     *
     * @return array{0: ?array, 1: ?string} the merged settings, or an error
     */
    public static function mergeSettings(?Community $community, array $patch): array
    {
        $current = self::settings($community);

        foreach (['post_permission', 'chat_permission'] as $key) {
            if (! array_key_exists($key, $patch)) {
                continue;
            }
            if (self::audience($patch[$key]) === null) {
                return [null, 'Choose who can contribute: '.implode(', ', self::AUDIENCES).'.'];
            }
            $current[$key] = self::audience($patch[$key]);
        }

        foreach ($patch as $key => $value) {
            if (in_array($key, ['post_permission', 'chat_permission', 'chat_locked'], true)) {
                continue;
            }
            $current[$key] = $value;
        }

        // Derived from chat_permission, never taken from the request: a patch
        // sending chat_permission=members with chat_locked=true would otherwise
        // store a row that contradicts itself.
        $current['chat_locked'] = $current['chat_permission'] === 'admins';

        return [$current, null];
    }

    /** A recognised audience, or null. */
    public static function audience($value): ?string
    {
        return is_string($value) && in_array($value, self::AUDIENCES, true) ? $value : null;
    }

    /** The acting role: owner beats whatever the membership row says. */
    public static function role(?Community $community, ?CommunityMember $membership, ?string $customerId): string
    {
        if ($customerId && $community && (string) $community->owner_customer_id === (string) $customerId) {
            return 'owner';
        }

        if (! $membership || $membership->status !== 'accepted') {
            return 'visitor';
        }

        return in_array($membership->role, self::ROLES, true) ? $membership->role : 'visitor';
    }

    public static function rank(string $role): int
    {
        $index = array_search($role, self::ROLES, true);

        return $index === false ? 0 : count(self::ROLES) - (int) $index;
    }

    /** Accepted membership of any role. Pending and removed are not members. */
    public static function isMember(string $role): bool
    {
        return $role !== 'visitor';
    }

    /** Owner and admins configure the community. */
    public static function canConfigure(string $role): bool
    {
        return in_array($role, ['owner', 'admin'], true);
    }

    /**
     * Moderators delete unwanted posts and group messages.
     *
     * Deliberately wider than canConfigure(): the document gives moderators
     * removal powers without any settings access.
     */
    public static function canModerate(string $role): bool
    {
        return in_array($role, ['owner', 'admin', 'moderator'], true);
    }

    /**
     * May this role host an event in the community?
     *
     * Hosting puts an event on the community's page and sells seats under its
     * name, so it is a moderation-level act rather than a member one — the same
     * bar as removing someone else's post. Until this existed there was no bar
     * at all: any signed-in user could pass any community's uuid when creating
     * an event, or attach an existing event to a community they had never
     * joined, and nothing on the server objected.
     *
     * Deliberately its own predicate rather than a call to canModerate() at each
     * site: if hosting is ever opened to members, there is one place to say so.
     */
    public static function canHostEvent(string $role): bool
    {
        return self::canModerate($role);
    }

    /** May this role contribute to this channel? */
    public static function canContribute(string $role, array $settings, string $channel): bool
    {
        if (! self::isMember($role)) {
            return false;
        }

        $permission = $channel === 'chat'
            ? ($settings['chat_permission'] ?? self::DEFAULTS['chat_permission'])
            : ($settings['post_permission'] ?? self::DEFAULTS['post_permission']);

        return match ($permission) {
            'admins'     => self::canConfigure($role),
            'moderators' => self::canModerate($role),
            default      => true,
        };
    }

    /**
     * May a moderator apply THIS patch?
     *
     * Moderators may change only the group-chat sending audience. A patch
     * carrying anything else — a feed permission, a name, a membership, an
     * owner — is refused outright rather than silently stripped: a moderator
     * told "saved" when half their request was dropped has been misled.
     */
    public static function isModeratorChatPatch(array $patch): bool
    {
        if (array_keys($patch) !== ['settings'] || ! is_array($patch['settings'])) {
            return false;
        }

        $keys = array_keys($patch['settings']);

        return $keys !== []
            && in_array('chat_permission', $keys, true)
            && ! array_diff($keys, self::MODERATOR_SETTINGS);
    }

    /**
     * May $actorRole set $targetRole to $newRole?
     *
     * The boundaries the document draws:
     *  - only the owner assigns or demotes admins;
     *  - owner and admins assign moderators;
     *  - the owner role is never assigned or taken away here (ownership
     *    transfer is a separate flow that does not exist);
     *  - nobody changes their own role, so an admin cannot promote themselves
     *    to owner-equivalent or demote the person who appointed them.
     *
     * The status matters as much as the refusal: 422 for a change nobody can
     * make whoever asks, 403 for one this person may not make.
     *
     * @return array{0: bool, 1: ?string, 2: int}
     */
    public static function canSetRole(string $actorRole, string $targetRole, string $newRole, bool $isSelf): array
    {
        if (! in_array($newRole, ['admin', 'moderator', 'member'], true)) {
            return [false, 'Choose admin, moderator or member.', 422];
        }

        // True for everyone, including the owner: ownership transfer is a
        // separate flow, and it does not exist.
        if ($targetRole === 'owner' || $newRole === 'owner') {
            return [false, 'The owner role cannot be changed here.', 422];
        }

        if (! self::canConfigure($actorRole)) {
            return [false, 'Only the owner and admins can change roles.', 403];
        }

        if ($isSelf) {
            return [false, 'You cannot change your own role.', 403];
        }

        // Admin is the owner's gift alone — in both directions, or an admin
        // could demote a peer and take over the community.
        if (($newRole === 'admin' || $targetRole === 'admin') && $actorRole !== 'owner') {
            return [false, 'Only the owner can assign or remove admins.', 403];
        }

        return [true, null, 200];
    }
}
