<?php

namespace App\Services;

use App\Models\Entry;
use App\Models\Handicap;
use App\Models\MatchResult;
use App\Models\Round;
use App\Models\Score;
use App\Models\Stage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/// TODO: Handle side-effects:
///  - League standings
///  - Entry handicap updates
class MatchService
{
    // Constants ----

    public const int LEAGUE_POINTS_FOR_WIN = 3;
    public const int LEAGUE_POINTS_FOR_DRAW = 1;
    public const int LEAGUE_POINTS_FOR_LOSS = 0;

    public const int BONUS_POINTS_FOR_HANDICAP_HIT = 1;
    public const int BONUS_POINTS_FOR_CLOSE_LOSS = 1;

    public const int CLOSE_LOSS_THRESHOLD = 5;

    public function __construct(protected HandicapService $handicapService) {}

    // Lookup ----
    //

    // Management ----

    /**
     * Record a new match result
     */
    public function recordMatchResult(array $data): MatchResult
    {
        return DB::transaction(function () use ($data): MatchResult {

            $stage  = Stage::findOrFail($data['stage_id']);
            $shotAt = CarbonImmutable::make($data['shot_at']);

            $match = MatchResult::make(['shot_at' => $shotAt]);

            // Determine the round in which this match took place
            $round = $this->resolveRound($stage, $shotAt);
            $match->round()->associate($round);

            // Associate the individual scores with the match result
            $leftScore = $this->recordScore($data['left_score']);
            $match->leftScore()->associate($leftScore);

            $rightScore = $this->recordScore($data['right_score']);
            $match->rightScore()->associate($rightScore);

            // Handle a drawn on decisive match outcome
            if ($leftScore->match_points_adjusted === $rightScore->match_points_adjusted) {
                $this->handleDrawnMatch($match, $leftScore, $rightScore);
            } else {
                $this->handleDecisiveMatch($match, $leftScore, $rightScore);
            }

            // Store the result
            $match->save();

            return $match;
        });
    }

    /**
     * Update an existing match result
     */
    public function updateMatchResult(MatchResult $match, array $data): MatchResult
    {
        return DB::transaction(function () use ($match, $data): MatchResult {

            $stage  = Stage::findOrFail($data['stage_id']);
            $shotAt = CarbonImmutable::make($data['shot_at']);

            // Determine the round in which this match took place
            $round = $this->resolveRound($stage, $shotAt);
            $match->round()->associate($round);

            // Update the individual scores
            $leftScore  = $this->updateScore($match->leftScore, $data['left_score']);
            $rightScore = $this->updateScore($match->rightScore, $data['right_score']);

            // Handle a drawn on decisive match outcome
            if ($leftScore->match_points_adjusted === $rightScore->match_points_adjusted) {
                $this->handleDrawnMatch($match, $leftScore, $rightScore);
            } else {
                $this->handleDecisiveMatch($match, $leftScore, $rightScore);
            }

            // Update the result
            $match->update(['shot_at' => $shotAt]);

            return $match;
        });
    }

    /**
     * Remove an existing match result
     */
    public function removeMatchResult(MatchResult $match): bool
    {
        return DB::transaction(function () use ($match): bool {
            $matchDeleted = $match->delete();

            $match->leftScore()->delete();
            $match->rightScore()->delete();

            return $matchDeleted;
        });
    }

    // Internals ----

    /**
     * Find the round in which a match was shot
     */
    protected function resolveRound(Stage $stage, CarbonInterface $shotAt): Round
    {
        return $stage->rounds()
            ->whereDate('starts_on', '<=', $shotAt)
            ->whereDate('ends_on', '>=', $shotAt)
            ->firstOrFail();
    }

    /**
     * Record an individual score for a match competitor
     */
    protected function recordScore(array $data): Score
    {
        $entry = Entry::findOrFail($data['entry_id']);

        $handicap = Handicap::whereBowStyle($entry->bow_style)
            ->whereNumber($entry->current_handicap)
            ->firstOrFail();

        $adjustedPoints = $data['match_points'] + $handicap->match_allowance;

        $newHandicap = $this->handicapService->recalculateHandicap($handicap, $data['match_points']);

        $score = Score::make([
            'handicap_before'       => $handicap->number,
            'handicap_after'        => $newHandicap->number,
            'allowance'             => $handicap->match_allowance,
            'match_points'          => $data['match_points'],
            'match_points_adjusted' => $adjustedPoints,
            'bonus_points'          => $adjustedPoints >= 1440 ? self::BONUS_POINTS_FOR_HANDICAP_HIT : 0,
            'league_points'         => 0, #This gets added when the scores are compared
        ]);

        $score->entry()->associate($entry);

        $score->save();

        return $score;
    }

    /**
     * Update an existing individual score for a match competitor
     */
    protected function updateScore(Score $score, array $data): Score
    {
        $entry = Entry::findOrFail($data['entry_id']);
        $score->entry()->associate($entry);

        $matchShotAt = $score->matchResult->shot_at;

        $handicapNumber = $entry
            ->scores()
            ->whereHas('matchResult', fn(Builder|MatchResult $match) => $match->shotBefore($matchShotAt))
            ->orderBy('handicap_after')
            ->value('handicap_after');

        $handicap = Handicap::whereBowStyle($entry->bow_style)
            ->whereNumber($handicapNumber)
            ->firstOrFail();

        $adjustedPoints = $data['match_points'] + $handicap->match_allowance;

        $newHandicap = $this->handicapService->recalculateHandicap($handicap, $data['match_points']);

        $score->update([
            'handicap_before'       => $handicap->number,
            'handicap_after'        => $newHandicap->number,
            'allowance'             => $handicap->match_allowance,
            'match_points'          => $data['match_points'],
            'match_points_adjusted' => $adjustedPoints,
            'bonus_points'          => $adjustedPoints >= 1440 ? self::BONUS_POINTS_FOR_HANDICAP_HIT : 0,
            'league_points'         => 0, #This gets added when the scores are compared
        ]);

        return $score;
    }

    /**
     * Handle a match ending in a draw
     */
    protected function handleDrawnMatch(MatchResult $match, Score $leftScore, Score $rightScore): void
    {
        $leftScore->update(['league_points' => self::LEAGUE_POINTS_FOR_DRAW]);
        $rightScore->update(['league_points' => self::LEAGUE_POINTS_FOR_DRAW]);

        $match->winner()->dissociate();
    }

    /**
     * Handle a match ending with a winner
     */
    protected function handleDecisiveMatch(MatchResult $match, Score $leftScore, Score $rightScore): void
    {
        /** @var Collection<int, Score> $scores */
        $scores = collect([$leftScore, $rightScore])->sortByDesc('match_points_adjusted');

        // Process the winning score
        $winningScore = $scores->first();
        $winningScore->update(['league_points' => self::LEAGUE_POINTS_FOR_WIN]);

        $match->winner()->associate($winningScore->entry);

        // Process the losing score
        $losingScore = $scores->last();
        $losingScore->update(['league_points' => self::LEAGUE_POINTS_FOR_LOSS]);

        $matchPointsDifference = $winningScore->match_points_adjusted - $losingScore->match_points_adjusted;

        if ($matchPointsDifference <= self::CLOSE_LOSS_THRESHOLD) {
            $losingScore->increment('bonus_points', self::BONUS_POINTS_FOR_CLOSE_LOSS);
        }
    }
}