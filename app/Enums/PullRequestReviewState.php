<?php

namespace App\Enums;

enum PullRequestReviewState: string
{
    case OPEN = 'open';
    case MERGED = 'merged';
    case CLOSED = 'closed';
    case DRAFT = 'draft';
    case UNKNOWN = 'unknown';
}
