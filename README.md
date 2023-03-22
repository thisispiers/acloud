# Acloud

Unofficial PHP client for Apple iCloud services.

> Apple and iCloud are trademarks of Apple Inc.

| Service | Supported |
|-|-|
| Calendar | ❌ |
| Contacts | ✅ Supported |
| Drive | ❌ |
| Find My | ❌ |
| Friends | ❌ |
| Mail | ❌ |
| Notes | ❌ |
| Photos | ❌ |
| Reminders | ❌ |

Feel free to add support for services by submitting a pull request.

## Installation

```
composer require thisispiers/acloud
```

## Usage

The tokens and cookies used for signing in are saved to the file path passed as the first argument to the constructor of `Session`. If you need to override this feature, pass in `null` or an empty string and use `getState()` and `getHttpCookieJar()`.

If a verification code is needed for signing in, `signIn()` will return the string `"MFA"` or the obfuscated phone number the code was sent to. Retrieve the verification code from the user and pass it to `verifyMFACode()`.

Make sure to check `isSignedIn()` after calling `signIn()` and `verifyMFACode()`.

Here's a basic example:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$acloud = new \thisispiers\Acloud\Session(__DIR__ . '/session');
if (!$acloud->isSignedIn()) {
    if (empty($_POST)) {
?>
<form method="POST">
    <p>Username: <input type="text" name="username"></p>
    <p>Password: <input type="password" name="password"></p>
    <button type="submit">Sign in</button>
</form>
<?php
    } else if (isset($_POST['username']) && isset($_POST['password'])) {
        $result = $acloud->signIn($_POST['username'], $_POST['password']);
        if ($signIn !== true) {
            if ($signIn === 'MFA') {
                echo '<p>Verification code sent to your device(s)</p>';
            } else {
                echo '<p>Verification code sent to ' . htmlspecialchars($signIn) . '</p>';
            }
?>
<form method="POST">
    Verification code: <input type="text" inputmode="numeric" name="verificationCode">
    <button type="submit">Sign in</button>
</form>
<?php
        }
    } else if (isset($_POST['verificationCode'])) {
        $acloud->verifyMFACode($_POST['verificationCode']);
        if (!$acloud->isSignedIn()) {
            echo '<p>Verification code invalid. Please try again.</p>';
        }
    }
}

if ($acloud->isSignedIn()) {
    $contacts = new \thisispiers\Acloud\Contacts($acloud);
    $allContacts = $contacts->list();
}
```

### API

```php
class Session
{
    public function __construct(?string $path = '');

    public function getHttpCookieJar(): \GuzzleHttp\Cookie\CookieJarInterface;

    public function loadState(?string $path = ''): bool;

    public function saveState(): bool;

    public function getState(): array;

    public function isSignedIn(): bool;

    public function signIn(string $username, string $password): true|string;

    public function sendMFACodeSMS(): false|string;

    public function verifyMFACode(string $code): bool;
}
```

Once signed in, pass the `Session` object into the service class constructor.

```php
class Contacts
{
    public function __construct(Session $session);

    public function list(): array;

    public function create(array $contacts): true;

    public function update(array $contacts): true;

    public function delete(array $contacts): true;
}
```

Read through the source code to find out which exceptions may be thrown.

---

### Thanks

Based on the following repositories:

- <https://github.com/foxt/icloud.js>
- <https://github.com/MauriceConrad/iCloud-API>
- <https://github.com/prabhu/iCloud>