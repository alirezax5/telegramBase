<?php

namespace Database;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Capsule\Manager as DB;

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

    /**
     * Insert the user if they do not already exist.
     *
     * @param int $chatid Telegram chat/user ID
     * @return bool True when a new row was inserted
     */
    public static function checkAndInsert(int $chatid): bool
    {
        if (self::check($chatid)) {
            return false;
        }

        return self::insertOrIgnore(['chatid' => $chatid]);
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