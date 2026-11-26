<?php

declare(strict_types=1);

use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Application-owned constraint; its message is never copied to an HTTP response. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class AllowedRegion extends Constraint
{
    public string $message = 'Region is unsupported.';
}

final class AllowedRegionValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (! $constraint instanceof AllowedRegion) {
            throw new LogicException('Unexpected region constraint.');
        }
        if ($value === null || $value === '' || in_array($value, ['NG', 'GH'], true)) {
            return;
        }
        $this->context->buildViolation($constraint->message)->setCode('APP_REGION')->addViolation();
    }
}

final class ShippingAddress
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 2)]
        public string $city,
        #[Assert\NotBlank]
        #[AllowedRegion]
        public string $region,
    ) {}
}

final class OrderRequest
{
    /** @param list<string> $tags */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 2)]
        public string $name,
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,
        #[Assert\PositiveOrZero]
        public int $price,
        #[Assert\Valid]
        public ShippingAddress $address,
        #[Assert\All([new Assert\NotBlank(), new Assert\Length(min: 2)])]
        public array $tags,
        #[Assert\NotBlank(groups: ['create'])]
        #[Assert\Length(min: 4, groups: ['update'])]
        public string $operationCode = '',
    ) {}
}

/** Register before freeze; application services receive ValidatorInterface in their constructor. */
function registerApplicationValidator(ServiceRegistry $services): void
{
    $services->register(
        ValidatorInterface::class,
        ServiceLifetime::Execution,
        static fn (): ValidatorInterface => Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator(),
    );
}

final class OrderValidationService
{
    public function __construct(private ValidatorInterface $validator) {}

    /** @param list<string>|null $groups */
    public function validate(OrderRequest $request, ?array $groups = null): ConstraintViolationListInterface
    {
        return $this->validator->validate($request, null, $groups);
    }
}

/** Typed construction is explicit; missing or malformed input is a separate application error. */
/** @param array<string, mixed> $input */
function makeOrderRequest(array $input): OrderRequest
{
    foreach (['name', 'email', 'price', 'address', 'tags'] as $key) {
        if (!array_key_exists($key, $input)) {
            throw new InvalidArgumentException('Malformed validation input.');
        }
    }
    if (!is_string($input['name']) || !is_string($input['email']) || !is_int($input['price'])
        || !is_array($input['address']) || !is_array($input['tags'])
        || !is_string($input['address']['city'] ?? null) || !is_string($input['address']['region'] ?? null)) {
        throw new InvalidArgumentException('Malformed validation input.');
    }
    foreach ($input['tags'] as $tag) {
        if (!is_string($tag)) {
            throw new InvalidArgumentException('Malformed validation input.');
        }
    }
    $code = $input['operationCode'] ?? '';
    if (!is_string($code)) {
        throw new InvalidArgumentException('Malformed validation input.');
    }
    return new OrderRequest(
        $input['name'], $input['email'], $input['price'],
        new ShippingAddress($input['address']['city'], $input['address']['region']),
        array_values($input['tags']), $code,
    );
}

/** @return array{status: 422, errors: list<array{field: string, code: string}>} */
function safeValidationResponse(ConstraintViolationListInterface $violations): array
{
    $errors = [];
    foreach ($violations as $violation) {
        $path = $violation->getPropertyPath();
        $field = in_array($path, ['name', 'email', 'price', 'address.city', 'address.region', 'operationCode'], true)
            || preg_match('/^tags\[\d+\]$/D', $path) === 1 ? $path : 'form';
        $constraint = $violation->getConstraint();
        $code = match (true) {
            $field === 'form' => 'invalid',
            $constraint instanceof Assert\NotBlank => 'required',
            $constraint instanceof Assert\Length => 'length',
            $constraint instanceof Assert\Email => 'email',
            $constraint instanceof Assert\PositiveOrZero => 'range',
            $constraint instanceof AllowedRegion => 'region',
            default => 'invalid',
        };
        $errors[] = ['field' => $field, 'code' => $code];
    }
    return ['status' => 422, 'errors' => $errors];
}