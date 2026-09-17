<?php

namespace App\Notifications\Concerns;

trait FormatsSlackMentions
{
    private function slackMentionsPrefix(): string
    {
        return $this->mentions === '' ? '' : implode(' ', array_map(fn (string $id): string => "<@{$id}>", explode(' ', $this->mentions))).' ';
    }
}
