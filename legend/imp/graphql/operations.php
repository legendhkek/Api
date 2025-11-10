<?php
declare(strict_types=1);

/**
 * Central registry for Shopify checkout GraphQL operations.
 *
 * Each .graphql file in this directory contains the raw document that was
 * previously embedded inside jsonp.php. Having them broken out into discrete
 * files makes it easier to maintain, diff, and lint the individual operations.
 *
 * The returned array is keyed by operation name so that existing helper
 * functions (extractOperationQueryFromFile) can request them by name.
 */
return [
    'Proposal' => file_get_contents(__DIR__ . '/Proposal.graphql'),
    'SubmitForCompletion' => file_get_contents(__DIR__ . '/SubmitForCompletion.graphql'),
    'PollForReceipt' => file_get_contents(__DIR__ . '/PollForReceipt.graphql'),
];

