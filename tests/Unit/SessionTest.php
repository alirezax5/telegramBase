<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Session\Session;
use alirezax5\TelegramBase\App\Session\SessionManager;
use alirezax5\TelegramBase\App\Cache\CacheManager;

class SessionTest extends TgBaseTestCase
{
    private function ensureCacheInitialized(): void
    {
        if (!CacheManager::isInitialized()) {
            CacheManager::init();
        }
    }

    public function test_start_creates_empty_session(TgAssert $a): void
    {
        $this->ensureCacheInitialized();
        $chatId = 999000001;
        SessionManager::flush($chatId);

        $session = SessionManager::start($chatId);

        $a->equals($chatId, $session->chatId());
        $a->true($session->id() !== '');
        $a->equals([], $session->all());
    }

    public function test_set_get_roundtrip(TgAssert $a): void
    {
        $this->ensureCacheInitialized();
        $chatId = 999000002;
        SessionManager::flush($chatId);

        $session = SessionManager::start($chatId);
        $session->set('step', 'waiting_email');
        $session->set('count', 42);

        $a->equals('waiting_email', $session->get('step'));
        $a->equals(42, $session->get('count'));
        $a->true($session->has('step'));
        $a->false($session->has('nonexistent'));
    }

    public function test_get_returns_default(TgAssert $a): void
    {
        $this->ensureCacheInitialized();
        $chatId = 999000003;
        SessionManager::flush($chatId);

        $session = SessionManager::start($chatId);
        $a->equals('fallback', $session->get('missing', 'fallback'));
    }

    public function test_forget_removes_key(TgAssert $a): void
    {
        $this->ensureCacheInitialized();
        $chatId = 999000004;
        SessionManager::flush($chatId);

        $session = SessionManager::start($chatId);
        $session->set('temp', 'value');
        $session->forget('temp');

        $a->false($session->has('temp'));
    }

    public function test_setmany_merges(TgAssert $a): void
    {
        $this->ensureCacheInitialized();
        $chatId = 999000005;
        SessionManager::flush($chatId);

        $session = SessionManager::start($chatId);
        $session->set('keep', 'orig');
        $session->setMany(['a' => 1, 'b' => 2]);

        $a->equals('orig', $session->get('keep'));
        $a->equals(1, $session->get('a'));
        $a->equals(2, $session->get('b'));
    }

    public function test_regenerate_changes_id(TgAssert $a): void
    {
        // This exercises the F4 fix — previously assigning to a readonly
        // property would fatal. Now it must succeed and return a new id.
        $this->ensureCacheInitialized();
        $chatId = 999000006;
        SessionManager::flush($chatId);

        $session = SessionManager::start($chatId);
        $oldId = $session->id();
        $newId = $session->regenerate();

        $a->true($oldId !== $newId, 'regenerate() must return a different id');
        $a->equals($newId, $session->id());
    }

    public function test_destroy_clears_session(TgAssert $a): void
    {
        $this->ensureCacheInitialized();
        $chatId = 999000007;
        SessionManager::flush($chatId);

        $session = SessionManager::start($chatId);
        $session->set('data', 'x');
        $session->destroy();

        $a->false(SessionManager::has($chatId));
    }

    public function test_persistence_across_instances(TgAssert $a): void
    {
        $this->ensureCacheInitialized();
        $chatId = 999000008;
        SessionManager::flush($chatId);

        $session1 = SessionManager::start($chatId);
        $session1->set('persisted', 'yes');

        // Re-open — should see the value.
        $session2 = SessionManager::start($chatId);
        $a->equals('yes', $session2->get('persisted'));
    }

    public function test_fromarray_hydrates(TgAssert $a): void
    {
        $raw = [
            'id' => 'abc123',
            'data' => ['k' => 'v'],
            'last_activity' => 1700000000,
        ];

        $s = Session::fromArray(123, $raw);
        $a->equals(123, $s->chatId());
        $a->equals('abc123', $s->id());
        $a->equals('v', $s->get('k'));
        $a->equals(1700000000, $s->lastActivity());
    }

    public function test_toarray_serializes(TgAssert $a): void
    {
        $this->ensureCacheInitialized();
        $chatId = 999000009;
        SessionManager::flush($chatId);
        $s = SessionManager::start($chatId);
        $s->set('k', 'v');

        $arr = $s->toArray();
        $a->true(isset($arr['id']));
        $a->true(isset($arr['data']['k']));
        $a->true(isset($arr['last_activity']));
    }
}
