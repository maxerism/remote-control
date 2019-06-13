<?php

namespace RemoteControl;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

class Manager implements Contracts\Factory
{
    /**
     * Indicates if remote control migrations will be run.
     *
     * @var bool
     */
    public static $runsMigrations = true;

    /**
     * The application instance.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * Remote control configuration.
     *
     * @var array
     */
    protected $config;

    /**
     * Construct a new remote control manager.
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     * @param array                                        $config
     */
    public function __construct(Application $app, array $config)
    {
        $this->app = $app;
        $this->config = $config;
    }

    /**
     * Configure Passport to not register its migrations.
     *
     * @return void
     */
    public static function ignoreMigrations(): void
    {
        static::$runsMigrations = false;
    }

    /**
     * Create remote request.
     *
     * @param \Illuminate\Contracts\Auth\Authenticatable $user
     * @param string                                     $email
     *
     * @return \RemoteControl\Contracts\AccessToken
     */
    public function create(Authenticatable $user, string $email): Contracts\AccessToken
    {
        return \tap($this->createTokenRepository()->create($user, $email), function ($accessToken) use ($user) {
            \event(new Events\RemoteAccessCreated($user, $accessToken));
        });
    }

    /**
     * Authenticate remote request.
     *
     * @param string $email
     * @param string $secret
     * @param string $verificationCode
     *
     * @return bool
     */
    public function authenticate(string $email, string $secret, string $verificationCode): bool
    {
        $repository = $this->createTokenRepository();

        $accessToken = $repository->query($email, $secret, $verificationCode);

        if (! $accessToken instanceof Contracts\AccessToken) {
            return false;
        }

        \tap($accessToken->authenticate($this->app['auth']->guard()), function ($user) use ($repository, $accessToken) {
            $repository->markAsUsed($accessToken->getId());

            if ($user instanceof Authenticatable) {
                \event(new Events\RemoteAccessUsed($user, $accessToken));
            }
        });

        return true;
    }

    /**
     * Create routes for remote control.
     *
     * @param string $uri
     *
     * @return \Illuminate\Routing\Route
     */
    public function verifyRoute(string $prefix): Route
    {
        return $this->app['router']->get('{secret}', Http\VerifyAccessController::class)
                    ->prefix(\rtrim($prefix, '/'))
                    ->name('remote-control.verify');
    }

    /**
     * Create a token repository instance based on the given configuration.
     *
     * @return \RemoteControl\Contracts\TokenRepository
     */
    protected function createTokenRepository(): Contracts\TokenRepository
    {
        $key = $this->config['key'];

        if (Str::startsWith($key, 'base64:')) {
            $key = \base64_decode(\substr($key, 7));
        }

        return new DatabaseTokenRepository(
            $this->app['db']->connection($this->config['database']['connection'] ?? null),
            $this->app['hash'],
            $this->config['database']['table'] ?? 'user_remote_controls',
            $key
        );
    }
}
