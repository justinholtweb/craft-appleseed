<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Mail;

use CraftCms\Cms\Email\Mailables\CraftMailable;

/**
 * The scan report email. A {@see CraftMailable}, so the from address, reply-to and mailer come from
 * Craft's email settings. One per recipient, as in Appleseed 5: recipients don't see each other.
 */
class ScanReportMail extends CraftMailable
{
    public function __construct(
        string $subject,
        public string $htmlBody,
    ) {
        $this->subject = $subject;
    }

    public function build(): static
    {
        return $this->html($this->htmlBody);
    }
}
