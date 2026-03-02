<?php

namespace App\Mail;

use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public TeamInvitation $invitation,
        public bool $isNewUser = false
    ) {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->isNewUser
            ? __('teams.new_user_invitation_subject', ['team' => $this->invitation->team->name])
            : __('teams.existing_user_invitation_subject', ['team' => $this->invitation->team->name]);

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $view = $this->isNewUser ? 'emails.team-invitation-new-user' : 'emails.team-invitation-existing-user';

        return new Content(
            view: $view,
            with: [
                'invitation' => $this->invitation,
                'team' => $this->invitation->team,
                'inviter' => $this->invitation->inviter,
                'acceptUrl' => $this->isNewUser
                    ? route('teams.invitations.register', ['token' => $this->invitation->token])
                    : route('teams.invitations.show', ['token' => $this->invitation->token]),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
