<?php

declare(strict_types=1);

namespace Evolve\Insight\Capture;

enum DiagnosticDataClassification: string
{
    case PublicOperationalMetadata = 'public-operational-metadata';
    case InternalOperationalMetadata = 'internal-operational-metadata';
    case PersonalData = 'personal-data';
    case AuthenticationData = 'authentication-data';
    case SecretData = 'secret-data';
    case BusinessSensitivePayload = 'business-sensitive-payload';
    case RegulatedData = 'regulated-data';
}
