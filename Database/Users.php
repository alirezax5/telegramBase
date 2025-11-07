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
        'status' => 'boolean'
    ];
    public $timestamps = false;

    public static function checkAndInsert(int $chatid): bool
    {
        return !self::check($chatid) && self::insertOrIgnore(['chatid' => $chatid]);
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
    public static function getAllStatusActiveUserByLang($lang = 'all',bool $limit = true, int $page = 1, int $per = 20)
    {
        if ($lang == 'all')
        $query = self::where('status', true)->orderBy('id');
        else
           $query = self::where('status', true)->where('lang', $lang)->orderBy('id');

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

    public static function getCountByField(string $field, $value): int
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

    public static function getUserById($id)
    {
        return self::where('id', $id)->first();

    }



    public static function updateFieldByChatId(int $chatid, string $field, $value): bool
    {
        return self::where('chatid', $chatid)->update([$field => $value]);
    }

    public static function updateCommand(int $chatid, $values): bool
    {
        return self::updateFieldByChatId($chatid, 'command', $values);
    }



    public static function updateData(int $chatid, $values): bool
    {
        return self::updateFieldByChatId($chatid, 'data', $values);
    }

    public static function updateRole(int $chatid, $values): bool
    {
        return self::updateFieldByChatId($chatid, 'role', $values);
    }



    public static function updateStatus(int $chatid, $values): bool
    {
        return self::updateFieldByChatId($chatid, 'status', $values);
    }

    public static function updateActive(int $chatid, $values): bool
    {
        return self::updateFieldByChatId($chatid, 'active', $values);
    }


    public static function updateLang(int $chatid, $values): bool
    {
        return self::updateFieldByChatId($chatid, 'lang', $values);
    }



    public static function getRecentUsers()
    {
        $twentyFourHoursAgo = Carbon::today();

        $recentUsers = DB::table('users')
            ->where('create_at', '>=', $twentyFourHoursAgo)
            ->count();

        return $recentUsers;
    }

}
