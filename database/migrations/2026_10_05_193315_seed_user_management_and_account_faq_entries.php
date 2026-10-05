<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MANAGEMENT_CATEGORY = 'User Management';

    private const ACCOUNT_CATEGORY = 'Your Account';

    /**
     * Same structure as 2026_09_09_174239_seed_dropbox_faq_entries.php. Orders start below the
     * Slack/Dropbox entries' 10 so these two categories are listed first on the /faq page,
     * which groups by first appearance in (order, id) order.
     */
    public function up(): void
    {
        $now = now();
        $rows = [];

        foreach ([self::MANAGEMENT_CATEGORY => $this->managementFaqs(), self::ACCOUNT_CATEGORY => $this->accountFaqs()] as $category => $faqs) {
            foreach ($faqs as $index => $faq) {
                $rows[] = [
                    ...$faq,
                    'category' => $category,
                    'order' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('faqs')->insert($rows);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('faqs')->whereIn('category', [self::MANAGEMENT_CATEGORY, self::ACCOUNT_CATEGORY])->delete();
    }

    /**
     * @return list<array{question: string, answer: string, keywords: string}>
     */
    private function managementFaqs(): array
    {
        return [
            [
                'question' => 'How do I invite a new person to my organization?',
                'answer' => 'Org Admins can invite people. Open your organization (choose "Organization" from the menu under your name), go to the Team tab, and click "Invite User". Enter their first name, last name, email address, and role (Team Member, Project Lead, or Org Admin), then click "Send Invitation". They\'ll get an email titled "You\'ve been invited to join <your organization> on Projector" with a link that works for 7 days. If that email address already has a Projector account, they\'re added to the organization right away instead, and no invitation is sent.',
                'keywords' => 'invite, invitation, new user, add user, team, email, onboarding',
            ],
            [
                'question' => 'What\'s the difference between "Add User" and "Invite User"?',
                'answer' => '"Invite User" works for anyone: you enter an email address, and they get an invitation link to create their account (or are added immediately if they already have one). "Add User" is a shortcut for people who already belong to another organization where you\'re an Org Admin: pick them from the list and they\'re added to the organization right away as a Team Member — you can change their role afterward from the Team list. Both buttons are on the organization\'s Team tab and are only shown to Org Admins.',
                'keywords' => 'add user, invite user, existing account, team, difference',
            ],
            [
                'question' => 'How do I resend, edit, or cancel an invitation?',
                'answer' => 'Invitations that haven\'t been accepted yet are listed under "Pending Invitations" below the team list on the Team tab. For each one you can:
- Resend — emails the same invitation again and gives them a fresh 7 days.
- Copy Link — copies the invitation link so you can send it yourself, e.g. in Slack or a text message.
- Edit (pencil icon) — change their name, email address, or role, then click "Save and Resend". This also gives them a fresh 7 days.
- Delete — cancels the invitation, and the link stops working. Any tasks you\'d assigned to that person become unassigned.

Once an invitation expires it drops off the list (unless tasks are assigned to that person — see the next question); just invite the person again. Inviting someone again reuses their existing invitation, so anything already assigned to them stays assigned.',
                'keywords' => 'pending invitation, resend, edit, cancel, revoke, delete, copy link, expired',
            ],
            [
                'question' => 'Can I assign tasks to someone who hasn\'t accepted their invitation yet?',
                'answer' => 'Yes. People with a pending invitation appear in the assignee list alongside everyone else, shown grayed out until they join. As soon as they accept the invitation, those tasks become theirs. While they have tasks assigned, their invitation link keeps working and stays on the Pending Invitations list even past the usual 7 days. Deleting their invitation unassigns those tasks; resending, editing, or inviting them again doesn\'t.',
                'keywords' => 'assign, assignee, task, pending invitation, invited user',
            ],
            [
                'question' => 'What can each role do?',
                'answer' => '- Org Admin — everything in the organization: inviting and managing people, changing roles, the Configuration tab (Slack, Dropbox, and other settings), creating, editing, and deleting projects, and managing project types and Transformations.
- Project Lead — sees every project in the organization and can use the Import Wizard and manage project tags, but can\'t create or delete projects, change organization settings, or manage the team.
- Team Member — sees only the projects for the clients they\'ve been given access to, and can work on tasks and documents in those projects.',
                'keywords' => 'role, roles, permissions, org admin, project lead, team member, access',
            ],
            [
                'question' => 'How do I change someone\'s role or remove them from the organization?',
                'answer' => 'Org Admins can do this from the organization\'s Team tab. Use the role dropdown next to the person\'s name to pick Org Admin, Project Lead, or Team Member — the change takes effect immediately. To remove someone from the organization, choose "No role" from that same dropdown. This removes them from this organization only; their Projector account and any other organizations they belong to aren\'t affected.',
                'keywords' => 'change role, remove user, team, permissions, no role, deactivate',
            ],
        ];
    }

    /**
     * @return list<array{question: string, answer: string, keywords: string}>
     */
    private function accountFaqs(): array
    {
        return [
            [
                'question' => 'I received an invitation email. How do I join?',
                'answer' => 'Click the link in the email. If you don\'t have a Projector account yet, you\'ll see a sign-up page for that organization with your name and email address already filled in — choose a password, confirm it, and you\'re in. If you already have an account under that email address, you\'ll be asked to sign in instead, and you\'re added to the organization as soon as you do. Either way, you land on that organization\'s dashboard. The link works for 7 days and only for the email address it was sent to; if it has expired, ask your Org Admin to send a new one.',
                'keywords' => 'invitation, accept, join, sign up, register, new user, link expired',
            ],
            [
                'question' => 'How do I update my name, email address, or timezone?',
                'answer' => 'Click your name at the bottom of the sidebar, choose "Settings", and you\'ll be on the Profile page. Update your first name, last name, email address, or timezone, then click "Save". Your timezone defaults to UTC, so it\'s worth setting — it\'s used for things like sending you the Slack daily digest at your own local morning.',
                'keywords' => 'profile, name, email, timezone, update, settings, new user',
            ],
            [
                'question' => 'How do I change my password, or reset it if I\'ve forgotten it?',
                'answer' => 'To change it, go to Settings > Password, enter your current password, then your new password twice, and save. If you\'ve forgotten it, click "Forgot password?" on the sign-in page and enter your email address — you\'ll get an email with a link to choose a new one.',
                'keywords' => 'password, change password, forgot password, reset, sign in, login',
            ],
            [
                'question' => 'How do I turn on two-factor authentication?',
                'answer' => 'Go to Settings > Two-Factor Auth and click "Enable 2FA". After confirming your password, scan the QR code with an authenticator app (such as Google Authenticator, 1Password, or Authy) and enter the 6-digit code it shows to finish. Save your recovery codes somewhere safe — each one can be used once to sign in if you lose access to your authenticator app. You can turn it off again from the same page with "Disable 2FA".',
                'keywords' => 'two-factor, 2fa, authenticator, security, recovery codes, mfa',
            ],
            [
                'question' => 'How do I switch to dark mode?',
                'answer' => 'Go to Settings > Appearance and choose Light, Dark, or System (which follows your computer\'s own setting).',
                'keywords' => 'appearance, dark mode, light mode, theme, display',
            ],
            [
                'question' => 'I belong to more than one organization. How do I switch between them?',
                'answer' => 'Use the organization picker at the top of the page. Switching takes you to that organization\'s dashboard, and everything you see — projects, tasks, and the team — is for the organization you\'ve selected.',
                'keywords' => 'switch organization, multiple organizations, organization picker',
            ],
            [
                'question' => 'How do I delete my account?',
                'answer' => 'Go to Settings > Profile, scroll to "Delete account" at the bottom, and confirm with your password. This permanently deletes your account and signs you out — it can\'t be undone. If you only want to leave one organization, ask an Org Admin to remove you instead.',
                'keywords' => 'delete account, close account, remove account, leave organization',
            ],
        ];
    }
};
