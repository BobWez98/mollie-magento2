<?php

namespace Mollie\Payment\Test\Integration\GraphQL\Resolver\Checkout\ApplePay;

use Mollie\Payment\Service\Mollie\ApplePay\Validation;
use Mollie\Payment\Test\Fakes\Service\Mollie\ApplePay\FakeValidator;
use Mollie\Payment\Test\Integration\GraphQLTestCase;

/**
 * @magentoAppArea graphql
 */
class ApplePayValidationTest extends GraphQLTestCase
{
    public function testValidates(): void
    {
        $this->objectManager->addSharedInstance(
            $this->objectManager->get(FakeValidator::class),
            Validation::class
        );

        $result = $this->graphQlQuery('mutation {
            mollieApplePayValidation(
                domain: "www.example.com"
                validationUrl: "https://example.com/applepay/validationurl"
            ) {
                response
            }
        }');

        $this->assertArrayHasKey('mollieApplePayValidation', $result);
        $this->assertArrayHasKey('response', $result['mollieApplePayValidation']);
        $this->assertEquals('fake-response', $result['mollieApplePayValidation']['response']);
    }
}
