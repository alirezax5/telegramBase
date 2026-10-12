<?php

namespace Database;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\QueryException;

class Users extends Model
{
    protected $table = 'users';
    protected $primaryKey = 'chatid';
    protected $casts = [
        'active' => 'boolean',
        'status' => 'boolean',
    ];
    public $timestamps = false;
    protected $connection = 'main';

    /** Role tiers, lowest to highest. 'superadmin' is never stored in DB —
     * it resolves from the ADMINS_WHITELIST env (bot owners). */
    public const ROLE_USER = 'user';
    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_SUPERADMIN = 'superadmin';

    /** @var array<string,int> role => rank */
    public const ROLE_HIERARCHY = [
        self::ROLE_USER => 0,
        self::ROLE_MODERATOR => 1,
        self::ROLE_ADMIN => 2,
        self::ROLE_SUPERADMIN => 3,
    ];

    /**
     * Insert the user if they do not already exist.
     *
     * Optional seeding: the language_code hint captured from the /start
     * update and any extra profile columns (username, first_name, ...).
     * Seeding happens on INSERT only — existing rows are never overwritten
     * (a returning user keeps their chosen language).
     *
     * @param int      $chatid Telegram chat/user ID
     * @param string|null $lang Language code to seed for NEW users (nullable
     *                         column; NULL means "not chosen yet")
     * @param array    $extra  Extra columns for the INSERT (column => value)
     * @return bool True when a new row was inserted
     */
    public static function checkAndInsert(int $chatid, ?string $lang = null, array $extra = []): bool
    {
        if (self::check($chatid)) {
            return false;
        }

        $payload = array_merge(['chatid' => $chatid], $extra);

        if ($lang !== null) {
            $payload['lang'] = $lang;
        }

        return self::insertOrIgnore($payload);
    }

    /**
     * Whether the user already exists.
     *
     * @param int $chatid Telegram chat/user ID
     * @return bool
     */
    public static function check(int $chatid): bool
    {
        return self::where('chatid', $chatid)->exists();
    }

    // -----------------------------------------------------------------
    // Role tiers: user < moderator < admin < superadmin(env)
    // -----------------------------------------------------------------

    /**
     * Numeric rank of a role name. Unknown roles rank below 'user' (-1)
     * so a typo in promote code can never grant accidental privileges.
     */
    public static function rankOf(string $role): int
    {
        return self::ROLE_HIERARCHY[$role] ?? -1;
    }

    /**
     * Whether a chat id belongs to a bot owner (ADMINS_WHITELIST env,
     * comma-separated chat ids). Same list AdminGuardMiddleware enforces —
     * owners implicitly hold the superadmin tier.
     */
    public static function isSuperAdmin(int $chatid): bool
    {
        $allowed = trim((string) \alirezax5\TelegramBase\App\Environment\EnvHandler::get('ADMINS_WHITELIST', ''));

        if ($allowed === '') {
            return false;
        }

        return in_array((string) $chatid, array_map('trim', explode(',', $allowed)), true);
    }

    /**
     * The user's stored role ('user' when unknown / missing row /
     * pre-migration table without the role column).
     */
    public static function roleOf(int $chatid): string
    {
        try {
            $role = self::where('chatid', $chatid)->value('role');
        } catch (QueryException) {
            return self::ROLE_USER; // pre-migration schema — fail safe, not open
        }

        return is_string($role) && isset(self::ROLE_HIERARCHY[$role])
            ? $role
            : self::ROLE_USER;
    }

    /**
     * Assign a role. Unknown role names are rejected (return false).
     * 'superadmin' is env-only and cannot be stored.
     */
    public static function setRole(int $chatid, string $role): bool
    {
        if (!isset(self::ROLE_HIERARCHY[$role]) || $role === self::ROLE_SUPERADMIN) {
            return false;
        }

        try {
            return self::updateFieldByChatId($chatid, 'role', $role);
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * Whether the user holds EXACTLY this role (superadmins from
     * ADMINS_WHITELIST satisfy any role check).
     */
    public static function hasRole(int $chatid, string $role): bool
    {
        if (self::isSuperAdmin($chatid)) {
            return true;
        }

        return self::roleOf($chatid) === $role;
    }

    /**
     * Whether the user's role ranks at least at the given tier
     * (user < moderator < admin < superadmin). This is the check
     * RoleMiddleware and admin panels should use.
     */
    public static function isAtLeast(int $chatid, string $role): bool
    {
        if (self::isSuperAdmin($chatid)) {
            return true;
        }

        $required = self::rankOf($role);
        if ($required < 0) {
            return false; // unknown required role — deny
        }

        return self::rankOf(self::roleOf($chatid)) >= $required;
    }

    /**
     * All active (status=true) users, optionally paginated.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator|\Illuminate\Database\Eloquent\Collection
     */
    public static function getAllStatusActiveUser(bool $limit = true, int $page = 1, int $per = 20)
    {
        $query = self::where('status', true)->orderBy('id');
        return $limit ? $query->paginate($per, ['*'], 'page', $page) : $query->get();
    }

    /**
     * Active users filtered by language, optionally paginated.
     *
     * @param string $lang Language code, or 'all' for every language
     * @return \Illuminate\Pagination\LengthAwarePaginator|\Illuminate\Database\Eloquent\Collection
     */
    public static function getAllStatusActiveUserByLang(string $lang = 'all', bool $limit = true, int $page = 1, int $per = 20)
    {
        $query = self::where('status', true);

        if ($lang !== 'all') {
            $query->where('lang', $lang);
        }

        $query->orderBy('id');

        return $limit ? $query->paginate($per, ['*'], 'page', $page) : $query->get();
    }

    /**
     * All active (active=true) users, newest first, optionally paginated.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator|\Illuminate\Database\Eloquent\Collection
     */
    public static function getAllActiveUser(bool $limit = true, int $page = 1, int $per = 20)
    {
        $query = self::where('active', true)->orderBy('id', 'DESC');
        return $limit ? $query->paginate($per, ['*'], 'page', $page) : $query->get();
    }

    /**
     * All users, optionally paginated.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator|\Illuminate\Database\Eloquent\Collection
     */
    public static function getAll(bool $limit = true, int $page = 1, int $per = 20)
    {
        $query = self::orderBy('id');
        return $limit ? $query->paginate($per, ['*'], 'page', $page) : $query->get();
    }

    /**
     * Users having a specific role (returns id + chatid only).
     *
     * @param string $role Role name, e.g. 'admin'
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getByRole(string $role)
    {
        return self::where('role', $role)->get(['id', 'chatid']);
    }

    /**
     * All users with the 'admin' role.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAdmins()
    {
        return self::getByRole('admin');
    }

    /**
     * Count users matching a field/value pair.
     *
     * @param string $field Column name
     * @param mixed  $value Match value
     */
    public static function getCountByField(string $field, mixed $value): int
    {
        return self::where($field, $value)->count();
    }

    /**
     * Number of active users.
     */
    public static function getCountActive(): int
    {
        return self::getCountByField('active', true);
    }

    /**
     * Number of inactive users.
     */
    public static function getCountNotActive(): int
    {
        return self::getCountByField('active', false);
    }

    /**
     * Total user count.
     */
    public static function getCountAll(): int
    {
        return self::count();
    }

    /**
     * Number of admins.
     */
    public static function getCountAdmin(): int
    {
        return self::getCountByField('role', 'admin');
    }

    /**
     * Number of VIP-type users.
     */
    public static function getCountVip(): int
    {
        return self::getCountByField('type', 'vip');
    }

    /**
     * Number of gold-type users.
     */
    public static function getCountGold(): int
    {
        return self::getCountByField('type', 'gold');
    }

    /**
     * Fetch a user by chat ID.
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public static function getUser(int $chatid)
    {
        return self::where('chatid', $chatid)->first();
    }

    /**
     * Fetch a user by primary key (id).
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public static function getUserById(int $id)
    {
        return self::where('id', $id)->first();
    }

    /**
     * Update a single column for one user.
     *
     * @return bool True when at least one record was updated
     */
    public static function updateFieldByChatId(int $chatid, string $field, mixed $value): bool
    {
        return (bool) self::where('chatid', $chatid)->update([$field => $value]);
    }

    /**
     * Set a user's current command state.
     */
    public static function updateCommand(int $chatid, mixed $value): bool
    {
        return self::updateFieldByChatId($chatid, 'command', $value);
    }

    /**
     * Set a user's data payload.
     */
    public static function updateData(int $chatid, mixed $value): bool
    {
        return self::updateFieldByChatId($chatid, 'data', $value);
    }

    /**
     * Set a user's role.
     */
    public static function updateRole(int $chatid, mixed $value): bool
    {
        return self::updateFieldByChatId($chatid, 'role', $value);
    }

    /**
     * Set a user's status flag.
     */
    public static function updateStatus(int $chatid, mixed $value): bool
    {
        return self::updateFieldByChatId($chatid, 'status', $value);
    }

    /**
     * Set a user's active flag.
     */
    public static function updateActive(int $chatid, mixed $value): bool
    {
        return self::updateFieldByChatId($chatid, 'active', $value);
    }

    /**
     * Set a user's language.
     */
    public static function updateLang(int $chatid, mixed $value): bool
    {
        return self::updateFieldByChatId($chatid, 'lang', $value);
    }

    /**
     * Number of users created in the last 24 hours.
     */
    public static function getRecentUsers(): int
    {
        $twentyFourHoursAgo = Carbon::now()->subDay();

        return DB::table('users')
            ->where('created_at', '>=', $twentyFourHoursAgo)
            ->count();
    }
}