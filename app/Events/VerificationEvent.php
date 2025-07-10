<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VerificationEvent
{
    use Dispatchable, SerializesModels;

    public $user;
    public $type;
    public $code;

    /**
     * Create a new event instance.
     */
    public function __construct($user, $type, $code)
    {
        $this->user = $user;
        $this->type = $type;
        $this->code = $code;
    }
}
