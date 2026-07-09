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

    public static function checkAndInsert(int $chatid): bool
    {
        if (self::check($chatid)) {
            return false;
        }

        return self::insertOrIgnore(['chatid' => $chatid]);
    }

    public static function check(int $chatid): bool
    {
        return self::where('chatid', $chatid)->exists();
    }

    public static function getAllStatusActiveUser(bool $limit = true, int $page = 1, int $per = 20)
    {
        $query = self::where('status', true)->orderBy('id');
        return $limit ? $query->paginate($per, ['*'], 'page', $page) : $query->get();
    }

    public static function getAllStatusActiveUserByLang(string $lang = 'all', bool $limit = true, int $page = 1, int $per = 20)
    {
        $query = self::where('status', true);

        if ($lang !== 'all') {
            $query->where('lang', $lang);
        }

        $query->orderBy('id');

        return $limit ? $query->paginate($per, ['*'], 'page', $page) : $query->get();
    }

    public static function getAllActiveUser(bool $limit = true, int $page = 1, int $per = 20)
    {
        $query = self::where('active', true)->orderBy('id', 'DESC');
        return $limit ? $query->paginate($per, ['*'], 'page', $page) : $query->get();
    }

    public static function getAll(bool $limit = true, int $page = 1, int $per = 20)
    {
        $query = self::orderBy('id');
        return $limit ? $query->paginate($per, ['*'], 'page', $page) : $query->get();
    }

    public static function getByRole(string $role)
    {
        return self::where('role', $role)->get(['id', 'chatid']);
    }

    public static function getAdmins()
    {
        return self::getByRole('admin');
    }

    public static function getCountByField(string $field, mixed $value): int
    {
        return self::where($field, $value)->count();
    }

    public static function getCountActive(): int
    {
        return self::getCountByField('active', true);
    }

    public static function getCountNotActive(): int
    {
        return self::getCountByField('active', false);
    }

    public static function getCountAll(): int
    {
        return self::count();
    }

    public static function getCountAdmin(): int
    {
        return self::getCountByField('role', 'admin');
    }

    public static function getCountVip(): int
    {
        return self::getCountByField('type', 'vip');
    }

    public static function getCountGold(): int
    {
        return self::getCountByField('type', 'gold');
    }

    public static function getUser(int $chatid)
    {
        return self::where('chatid', $chatid)->first();
    }

    public static function getUserById(int $id)
    {
        return self::where('id', $id)->first();
    }

    public static function updateFieldByChatId(int $chatid, string $field, mixed $value): bool
    {
        return (bool) self::where('chatid', $chatid)->update([$field => $value]);
    }

    public static function updateCommand(int $chatid, mixed $value): bool
    {
        return self::updateFieldByChatId($chatid, 'command', $value);
    }

    public static function updateData(int $chatid, mixed $value): bool
    {
        return self::updateFieldByChatId($chatid, 'data', $value);
    }

    public static function updateRole(int $chatid, mixed $value): bool
    {
        return self::updateFieldByChatId($chatid, 'role', $value);
    }

    public static function updateStatus(int $chatid, mixed $value): bool
    {
        return self::updateFieldByChatId($chatid, 'status', $value);
    }

    public static function updateActive(int $chatid, mixed $value): bool
    {
        return self::updateFieldByChatId($chatid, 'active', $value);
    }

    public static function updateLang(int $chatid, mixed $value): bool
    {
        return self::updateFieldByChatId($chatid, 'lang', $value);
    }

    public static function getRecentUsers(): int
    {
        $twentyFourHoursAgo = Carbon::now()->subDay();

        return DB::table('users')
            ->where('created_at', '>=', $twentyFourHoursAgo)
            ->count();
    }
}