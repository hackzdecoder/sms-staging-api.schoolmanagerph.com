<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class DatabaseManager
{
  const ENV_DEVELOPMENT = 'dev';
  const ENV_PRODUCTION = 'production';
  const CONNECTION_CACHE_KEY = 'database_connections';
  const CONNECTION_CACHE_TTL = 3600;

  /**
   * Connect to a database dynamically based on user/school
   */
  public static function connect(string $databaseName = null)
  {
    // If no database name provided, auto-detect based on user
    if (!$databaseName) {
      $databaseName = self::detectDatabaseName();
    }

    // Check if connection already exists and is valid
    if (self::isValidConnection($databaseName)) {
      return DB::connection($databaseName);
    }

    // Get credentials and create connection
    $credentials = self::getCredentials($databaseName);
    self::createConnection($databaseName, $credentials);

    // Test connection and cache if successful
    return self::testAndCacheConnection($databaseName);
  }

  /**
   * Automatically detect database name based on authenticated user
   */
  private static function detectDatabaseName(): string
  {
    // Try to get school code from authenticated user
    $schoolCode = self::detectSchoolCode();

    if (!$schoolCode) {
      throw new \Exception('Could not detect school code for database connection.');
    }

    // Generate database name
    return self::generateDatabaseName($schoolCode);
  }

  /**
   * Detect school code from authenticated user
   */
  private static function detectSchoolCode(): ?string
  {
    $user = Auth::user();

    if (!$user) {
      return null;
    }

    // Priority 1: Direct school_code attribute
    if (isset($user->school_code) && $user->school_code) {
      return strtoupper($user->school_code);
    }

    // Priority 2: From user_id pattern
    if (isset($user->user_id) && $user->user_id) {
      if (preg_match('/^([A-Z]{2,5})/', $user->user_id, $matches)) {
        return $matches[1];
      }
    }

    // Priority 3: From username
    if (isset($user->username) && $user->username) {
      if (preg_match('/^([A-Z]{2,5})/', $user->username, $matches)) {
        return $matches[1];
      }
    }

    // Priority 4: From email
    if (isset($user->email) && $user->email) {
      if (preg_match('/\.([A-Z]{2,5})@/', $user->email, $matches)) {
        return $matches[1];
      }
    }

    // Priority 5: Query users_main database
    try {
      $userRecord = DB::connection('users_main')
        ->table('users')
        ->where('id', $user->id)
        ->select('school_code')
        ->first();

      if ($userRecord && $userRecord->school_code) {
        return strtoupper($userRecord->school_code);
      }
    } catch (\Exception $e) {
      // Silently fail
    }

    return null;
  }

  /**
   * Generate school database name from school code - ABSOLUTE FIXED
   */
  public static function generateDatabaseName(string $schoolCode): string
  {
    $isDevelopment = app()->environment(self::ENV_DEVELOPMENT);
    $schoolCode = strtolower(trim($schoolCode));

    if ($isDevelopment) {
      return 'sm_' . $schoolCode . '_dev';
    }

    // PRODUCTION: Database is u141085058_{school_code} (NO "sm_" in database name)
    // Example: For JRU -> u141085058_jru
    return 'u141085058_' . $schoolCode;
  }

  /**
   * Test if connection is valid
   */
  private static function isValidConnection(string $databaseName): bool
  {
    if (!Config::has("database.connections.{$databaseName}")) {
      return false;
    }

    try {
      $connection = DB::connection($databaseName);
      $connection->getPdo();
      $connection->select('SELECT 1');
      return true;
    } catch (\Exception $e) {
      return false;
    }
  }

  /**
   * Get credentials for database connection - ABSOLUTE FIXED
   */
  private static function getCredentials(string $databaseName): array
  {
    $isDevelopment = app()->environment(self::ENV_DEVELOPMENT);
    $schoolCode = self::extractSchoolCodeFromDatabaseName($databaseName);

    if ($isDevelopment) {
      return [
        'driver' => 'mysql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '3306'),
        'database' => $databaseName,
        'username' => env('DB_USERS_MAIN_USERNAME', 'root'),
        'password' => env('DB_PASSWORD_DEVELOPMENT', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => null,
        'options' => [
          \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        ],
      ];
    }

    // PRODUCTION: Username is u141085058_sm_{school_code} ("sm_" in username only)
    // Example: For JRU -> username: u141085058_sm_jru
    $username = 'u141085058_sm_' . $schoolCode;

    return [
      'driver' => 'mysql',
      'host' => env('DB_HOST', '127.0.0.1'),
      'port' => env('DB_PORT', '3306'),
      'database' => $databaseName, // This is u141085058_jru
      'username' => $username,     // This is u141085058_sm_jru
      'password' => 'M@trix103!',  // Hardcoded production password
      'charset' => 'utf8mb4',
      'collation' => 'utf8mb4_unicode_ci',
      'prefix' => '',
      'strict' => true,
      'engine' => null,
      'options' => [
        \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
      ],
    ];
  }

  /**
   * Extract school code from database name - ABSOLUTE FIXED
   */
  private static function extractSchoolCodeFromDatabaseName(string $databaseName): string
  {
    // Remove u141085058_ prefix
    $schoolCode = preg_replace('/^u141085058_/', '', $databaseName);

    // Remove sm_ prefix if exists (for development)
    $schoolCode = preg_replace('/^sm_/', '', $schoolCode);

    // Remove _dev suffix if exists
    $schoolCode = preg_replace('/_dev$/', '', $schoolCode);

    return $schoolCode ?: $databaseName;
  }

  /**
   * Create database connection configuration
   */
  private static function createConnection(string $databaseName, array $config): void
  {
    Config::set("database.connections.{$databaseName}", $config);
  }

  /**
   * Test and cache connection
   */
  private static function testAndCacheConnection(string $databaseName)
  {
    try {
      $connection = DB::connection($databaseName);
      $connection->getPdo();

      self::addToCache($databaseName);
      return $connection;

    } catch (\Exception $e) {
      throw new \Exception(
        "Could not connect to database '{$databaseName}'. " .
        "Error: " . $e->getMessage()
      );
    }
  }

  /**
   * Add connection to cache
   */
  private static function addToCache(string $databaseName): void
  {
    $connections = Cache::get(self::CONNECTION_CACHE_KEY, []);
    $connections[$databaseName] = [
      'connected_at' => now()->toDateTimeString(),
    ];
    Cache::put(self::CONNECTION_CACHE_KEY, $connections, self::CONNECTION_CACHE_TTL);
  }

  /**
   * Remove connection from cache
   */
  private static function removeFromCache(string $databaseName): void
  {
    $connections = Cache::get(self::CONNECTION_CACHE_KEY, []);
    unset($connections[$databaseName]);
    Cache::put(self::CONNECTION_CACHE_KEY, $connections, self::CONNECTION_CACHE_TTL);
  }

  /**
   * Disconnect from database
   */
  public static function disconnect(string $databaseName = null): void
  {
    if (!$databaseName) {
      $databaseName = self::detectDatabaseName();
    }

    if (Config::has("database.connections.{$databaseName}")) {
      try {
        DB::disconnect($databaseName);
      } catch (\Exception $e) {
        // Ignore
      }
      Config::set("database.connections.{$databaseName}", null);
    }

    self::removeFromCache($databaseName);
  }

  /**
   * Clear all cached connections
   */
  public static function clearCache(): void
  {
    Cache::forget(self::CONNECTION_CACHE_KEY);
  }

  /**
   * Get current school database name
   */
  public static function getCurrentDatabaseName(): string
  {
    return self::detectDatabaseName();
  }

  /**
   * Get current school code
   */
  public static function getCurrentSchoolCode(): ?string
  {
    return self::detectSchoolCode();
  }
}