<?php

namespace Tests\Feature;

use App\Support\PlayerView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The server's player view against the shared cases in tests/fixtures/player-view.json, which
 * resources/js/lib/playerView.test.ts checks the TypeScript copy against too.
 */
class PlayerViewParityTest extends TestCase
{
    private static function fixture(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../fixtures/player-view.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function cases(): array
    {
        return collect(self::fixture()['cases'])->mapWithKeys(fn (array $case) => [$case['enemyHp'] => [$case['enemyHp'], $case['expected']]])->all();
    }

    #[DataProvider('cases')]
    public function test_the_player_view_matches_the_shared_case(string $enemyHp, array $expected)
    {
        $this->assertEquals($expected, PlayerView::fromFight(self::fixture()['fight'], $enemyHp));
    }
}
