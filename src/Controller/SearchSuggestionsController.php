<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Controller;

use Guc\SearchBundle\Repository\SearchRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns autocomplete word suggestions from the word dictionary (search_words table).
 * Used by the frontend to show instant prefix suggestions while the user is typing.
 */
class SearchSuggestionsController
{
    public function __construct(
        private readonly SearchRepository $searchRepository,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $prefix = mb_strtolower(trim($request->query->get('q', '')));

        if (mb_strlen($prefix) < 2) {
            return new JsonResponse(['suggestions' => []]);
        }

        // Reject non-letter prefixes (digits, symbols) — dictionary contains only letters
        if (!preg_match('/^\p{L}/u', $prefix)) {
            return new JsonResponse(['suggestions' => []]);
        }

        $suggestions = $this->searchRepository->getSuggestions($prefix, 8);

        $response = new JsonResponse(['suggestions' => $suggestions]);
        $response->setPrivate()->setMaxAge(60);

        return $response;
    }
}
