<?php
declare(strict_types=1);

/** Guests' citadel activities belong to their own clan. */
function tracker_is_guest_rank(?string $rank): bool
{
    return strtolower(trim((string)$rank)) === 'guest';
}
