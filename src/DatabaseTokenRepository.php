<?php

namespace RemoteControl;

use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher as HasherContract;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;

class DatabaseTokenRepository implements Contracts\TokenRepository
{
    use Concerns\GeneratesAccessTokens;

    /**
     * The database connection instance.
     *
     * @var \Illuminate\Database\ConnectionInterface
     */
    protected $connection;

    /**
     * The Hasher implementation.
     *
     * @var \Illuminate\Contracts\Hashing\Hasher
     */
    protected $hasher;

    /**
     * The token database table.
     *
     * @var string
     */
    protected $table;

    /**
     * The number of seconds a token should last.
     *
     * @var int
     */
    protected $expires;

    /**
     * Create a new token repository instance.
     *
     * @param \Illuminate\Database\ConnectionInterface $connection
     * @param \Illuminate\Contracts\Hashing\Hasher     $hasher
     * @param string                                   $table
     * @param string                                   $hashKey
     *
     * @return void
     */
    public function __construct(
        ConnectionInterface $connection,
        HasherContract $hasher,
        string $table,
        string $hashKey
    ) {
        $this->table = $table;
        $this->hasher = $hasher;
        $this->hashKey = $hashKey;
        $this->connection = $connection;
    }

    /**
     * Create a new token.
     *
     * @param \Illuminate\Contracts\Auth\Authenticatable $user
     * @param string                                     $email
     *
     * @return \RemoteControl\Contracts\AccessToken
     */
    public function create(Authenticatable $user, string $email): Contracts\AccessToken
    {
        // We will create a new, random token for the user so that we can e-mail them
        // a safe link to the password reset form. Then we will insert a record in
        // the database so that we can verify the token within the actual reset.
        $secret = $this->generateSecret();
        $verificationCode = $this->generateVerificationCode();

        $this->getTable()->insert($this->getPayload($email, $secret, $verificationCode));

        return new AccessToken($secret, $verificationCode, $user->getKey());
    }

    /**
     * Query existing record exists and yet to expired.
     *
     * @param string $email
     * @param string $secret
     * @param string $verificationCode
     *
     * @return \RemoteControl\Contracts\AccessToken|null
     */
    public function query(string $email, string $secret, string $verificationCode): ?Contracts\AccessToken
    {
        $record = $this->getTable()
                        ->where('email', $email)
                        ->where('verification_code', $verificationCode)
                        ->first();

        if (! \is_null($record)
            && ! $this->tokenExpired($record->created_at)
            && $this->hasher->check($secret, $record->secret)
        ) {
            return new AccessToken($secret, $verificationCode, $record->user_id);
        }

        return null;
    }

    /**
     * Determine if a token record exists and is valid.
     *
     * @param string $email
     * @param string $secret
     * @param string $verificationCode
     *
     * @return bool
     */
    public function exists(string $email, string $secret, string $verificationCode): bool
    {
        $record = $this->queryExisting($email, $secret, $verificationCode);

        return ! \is_null($record);
    }

    /**
     * Build the record payload for the table.
     *
     * @param string $email
     * @param string $secret
     * @param string $verificationCode
     *
     * @return array
     */
    protected function getPayload(Authenticatable $user, string $email, string $secret, string $verificationCode): array
    {
        return [
            'user_id' => $user->getKey(),
            'email' => $email,
            'secret' => $this->hasher->make($secret),
            'verification_code' => $verificationCode,
            'created_at' => new Carbon(),
        ];
    }

    /**
     * Get the database connection instance.
     *
     * @return \Illuminate\Database\ConnectionInterface
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * Get the hasher instance.
     *
     * @return \Illuminate\Contracts\Hashing\Hasher
     */
    public function getHasher(): HasherContract
    {
        return $this->hasher;
    }

    /**
     * Begin a new database query against the table.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getTable(): QueryBuilder
    {
        return $this->connection->table($this->table);
    }
}
