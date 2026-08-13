export interface AssigneeOption {
    value: string;
    label: string;
}

export interface MentionableUser {
    id: number | string;
    name: string;
    first_name: string;
    last_name: string;
}

// An invitation's name falls back to its email for older invitations sent before name
// capture existed (see OrgInvitationTable.vue for the same fallback).
export const invitationName = (inv: OrganizationInvitation): string =>
    [inv.first_name, inv.last_name].filter(Boolean).join(' ') || inv.email;

/**
 * Merges an organization's real users and its pending invitations into one
 * alphabetically-sorted list of assignable people, shared by every assignee picker
 * (document sidebar, task report search form, ...) so they can't drift apart.
 * Invitation values are prefixed `inv:` to distinguish them from real user ids, a
 * convention the backend mirrors (assignee_id vs. pending_assignee_invitation_id).
 */
export function mergeAssigneeOptions(users?: User[], invitations?: OrganizationInvitation[]): AssigneeOption[] {
    const userOptions: AssigneeOption[] = (users ?? []).map((user) => ({
        value: user.id.toString(),
        label: user.name,
    }));

    const invitationOptions: AssigneeOption[] = (invitations ?? []).map((inv) => ({
        value: `inv:${inv.id}`,
        label: invitationName(inv),
    }));

    return [...userOptions, ...invitationOptions].sort((a, b) => a.label.localeCompare(b.label));
}

/**
 * Same merge as mergeAssigneeOptions(), shaped for the @-mention picker (useDocumentEditor's
 * MentionList) instead of a <Select>. Ids follow the same `inv:` convention so a mention
 * round-trips through ProjectAiService::extractMentionedUsers()/resolveAssigneeIds() into
 * the correct assignee_id vs. pending_assignee_invitation_id column, exactly like the
 * assignee picker.
 */
export function mergeMentionableUsers(users?: User[], invitations?: OrganizationInvitation[]): MentionableUser[] {
    const userEntries: MentionableUser[] = (users ?? []).map((user) => ({
        id: user.id,
        name: user.name,
        first_name: user.first_name,
        last_name: user.last_name,
    }));

    const invitationEntries: MentionableUser[] = (invitations ?? []).map((inv) => ({
        id: `inv:${inv.id}`,
        name: invitationName(inv),
        first_name: inv.first_name ?? '',
        last_name: inv.last_name ?? '',
    }));

    return [...userEntries, ...invitationEntries].sort((a, b) => a.name.localeCompare(b.name));
}
