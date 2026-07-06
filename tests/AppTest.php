<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    public function testAcceptsAWellFormedChannelId(): void
    {
        $this->assertTrue(App::isChannelSlug('UC_x5XG1OV2P6uZZ5FSM9Ttw'));
    }

    public function testRejectsAnythingThatIsNotAChannelId(): void
    {
        $this->assertFalse(App::isChannelSlug('channels'));                 // a route, not an id
        $this->assertFalse(App::isChannelSlug('UCtooshort'));               // too short
        $this->assertFalse(App::isChannelSlug('UC_x5XG1OV2P6uZZ5FSM9Ttww')); // 23 id chars
        $this->assertFalse(App::isChannelSlug('XX_x5XG1OV2P6uZZ5FSM9Ttw'));  // wrong prefix
        $this->assertFalse(App::isChannelSlug('UC_x5XG1OV2P6uZZ5FSM9Tt.'));  // illegal char
    }
}
