<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionScopeClosed;
use Evolve\Core\Exception\ExecutionScopeUnavailable;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

$autoload = getenv('EVOLVE_VALIDATOR_CONSUMER_AUTOLOAD');
if (!is_string($autoload) || !is_file($autoload) || basename($autoload) !== 'autoload.php'
    || basename(dirname($autoload)) !== 'vendor') {
    fwrite(STDERR, "Set EVOLVE_VALIDATOR_CONSUMER_AUTOLOAD to the isolated consumer vendor/autoload.php.\n");
    exit(2);
}
require $autoload;
require __DIR__ . '/fixtures/symfony-validator-consumer.php';

function checkValidator(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectValidatorFailure(callable $operation, string $expected, string $message): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        checkValidator($error instanceof $expected, $message . ' Wrong exception category.');
        return;
    }
    throw new RuntimeException($message);
}

/** @return list<string> */
function paths(ConstraintViolationListInterface $violations): array
{
    $result = [];
    foreach ($violations as $violation) {
        $result[] = $violation->getPropertyPath();
    }
    return $result;
}

try {
    checkValidator(class_exists(ServiceRegistry::class), 'Core unavailable.');
    checkValidator(interface_exists(ValidatorInterface::class), 'Symfony Validator unavailable.');
    checkValidator(class_exists(InstalledVersions::class), 'Composer installation metadata unavailable.');
    $version = InstalledVersions::getVersion('symfony/validator');
    checkValidator(is_string($version) && version_compare($version, '7.4.23', '>=')
        && version_compare($version, '7.5.0', '<'), 'Validator version is outside patched 7.4 range.');
    $worktree = realpath(dirname(__DIR__));
    $vendor = realpath(dirname($autoload));
    $coreFile = realpath((string) (new ReflectionClass(ServiceRegistry::class))->getFileName());
    $validatorFile = realpath((string) (new ReflectionClass(ValidatorInterface::class))->getFileName());
    checkValidator(is_string($worktree) && is_string($coreFile)
        && str_starts_with(str_replace('\\', '/', $coreFile), str_replace('\\', '/', $worktree) . '/packages/core/'),
        'Core did not resolve from this worktree.');
    checkValidator(is_string($vendor) && is_string($validatorFile)
        && str_starts_with(str_replace('\\', '/', $validatorFile), str_replace('\\', '/', $vendor) . '/symfony/validator/'),
        'Validator did not resolve from the isolated consumer.');

    $services = new ServiceRegistry();
    registerApplicationValidator($services);
    $root = $services->freeze();
    checkValidator($root->has(ValidatorInterface::class), 'Validator binding absent.');
    expectValidatorFailure(static fn () => $root->get(ValidatorInterface::class), ExecutionScopeUnavailable::class,
        'Root resolved execution validator.');

    $valid = ['name' => 'Ada', 'email' => 'ada@example.test', 'price' => 0,
        'address' => ['city' => 'Lagos', 'region' => 'NG'], 'tags' => ['fresh', 'safe']];
    $sensitive = 'secret-token-4381';
    $invalid = ['name' => '', 'email' => $sensitive, 'price' => -1,
        'address' => ['city' => '', 'region' => 'XX'], 'tags' => ['okay', '']];

    $first = $services->createExecutionScope();
    try {
        $instance = $first->get(ValidatorInterface::class);
        checkValidator($instance === $first->get(ValidatorInterface::class), 'Validator changed within execution.');
        $app = new OrderValidationService($instance);
        checkValidator(count($app->validate(makeOrderRequest($valid))) === 0, 'Valid DTO failed default group.');
        $violations = $app->validate(makeOrderRequest($invalid));
        $found = paths($violations);
        foreach (['name', 'email', 'price', 'address.city', 'address.region', 'tags[1]'] as $expected) {
            checkValidator(in_array($expected, $found, true), 'Expected invalid field path absent: ' . $expected);
        }
        $response = safeValidationResponse($violations);
        $encoded = json_encode($response, JSON_THROW_ON_ERROR);
        checkValidator(str_contains($encoded, '"status":422') && count($response['errors']) >= 6,
            'Application HTTP 422 mapping incomplete.');
        checkValidator(!str_contains($encoded, $sensitive) && !str_contains($encoded, 'secret'),
            'Application response disclosed rejected input.');
        checkValidator(str_contains($encoded, '"field":"email"')
            && str_contains($encoded, '"code":"email"'), 'Application error shape incomplete.');
        $unknown = safeValidationResponse(new ConstraintViolationList([
            new ConstraintViolation('private ' . $sensitive, null, [], null, 'internal.path', $sensitive),
            new ConstraintViolation('private ' . $sensitive, null, [], null, 'name', $sensitive),
        ]));
        checkValidator($unknown['errors'] === [
            ['field' => 'form', 'code' => 'invalid'], ['field' => 'name', 'code' => 'invalid'],
        ], 'Unknown path or constraint code was not normalized.');
        checkValidator(!str_contains(json_encode($unknown, JSON_THROW_ON_ERROR), $sensitive),
            'Unknown violation disclosed rejected input.');

        $create = makeOrderRequest($valid);
        checkValidator(count($app->validate($create, ['Default'])) === 0, 'Default group activated operation rule.');
        checkValidator(in_array('operationCode', paths($app->validate($create, ['create'])), true),
            'Create group did not reject blank operation code.');
        $update = makeOrderRequest($valid + ['operationCode' => 'abc']);
        checkValidator(in_array('operationCode', paths($app->validate($update, ['update'])), true),
            'Update group did not reject short code.');
        checkValidator(!in_array('operationCode', paths($app->validate($update, ['create'])), true),
            'Create group executed update rule.');
        $validGroup = makeOrderRequest($valid + ['operationCode' => 'abcd']);
        checkValidator(count($app->validate($validGroup, ['create'])) === 0
            && count($app->validate($validGroup, ['update'])) === 0, 'Valid group input failed.');

        expectValidatorFailure(static fn () => makeOrderRequest([]), InvalidArgumentException::class,
            'Missing input was accepted.');
        expectValidatorFailure(static fn () => makeOrderRequest(array_replace($valid, ['tags' => [1]])),
            InvalidArgumentException::class, 'Malformed collection was accepted.');
    } finally {
        $first->close();
    }
    expectValidatorFailure(static fn () => $first->get(ValidatorInterface::class), ExecutionScopeClosed::class,
        'Closed scope remained usable.');

    $exceptionScope = $services->createExecutionScope();
    expectValidatorFailure(static function () use ($exceptionScope): void {
        try {
            $exceptionScope->get(ValidatorInterface::class);
            throw new LogicException('synthetic operation exception');
        } finally {
            $exceptionScope->close();
        }
    }, LogicException::class, 'Scoped application exception did not escape.');
    expectValidatorFailure(static fn () => $exceptionScope->get(ValidatorInterface::class),
        ExecutionScopeClosed::class, 'Exceptional scope remained usable.');

    $second = $services->createExecutionScope();
    try {
        $secondInstance = $second->get(ValidatorInterface::class);
        checkValidator($secondInstance !== $instance, 'Validator leaked between executions.');
        checkValidator(count((new OrderValidationService($secondInstance))->validate(makeOrderRequest($valid))) === 0,
            'Previous invalid input contaminated subsequent execution.');
    } finally {
        $second->close();
    }
    expectValidatorFailure(static fn () => $second->get(ValidatorInterface::class), ExecutionScopeClosed::class,
        'Second scope did not close.');

    echo "PASS isolated Symfony Validator consumer: DTOs, nested fields, collections, groups, custom constraint, safe 422 mapping and sequential scope isolation\n";
} catch (Throwable $error) {
    $detail = $error instanceof RuntimeException && $error::class === RuntimeException::class ? $error->getMessage() : $error::class;
    fwrite(STDERR, 'FAIL ' . $detail . "\n");
    exit(1);
}