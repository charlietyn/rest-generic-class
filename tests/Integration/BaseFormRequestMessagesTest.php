<?php

namespace Ronu\RestGenericClass\Tests\Integration;

use Illuminate\Validation\ValidationException;
use Orchestra\Testbench\TestCase;
use Ronu\RestGenericClass\Core\Requests\BaseFormRequest;

/** A request whose rules are inline: the scenario/rules-file machinery is not what is under test. */
class ScheduledRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return ['scheduled_at' => ['required', 'date']];
    }
}

final class BaseFormRequestMessagesTest extends TestCase
{
    private function validateWith(array $data): ValidationException
    {
        $request = ScheduledRequest::create('/appointments', 'POST', $data);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        try {
            $request->validate_request();
        } catch (ValidationException $e) {
            return $e;
        }
        $this->fail('The request should not validate.');
    }

    public function testAnArrayValueIsAValidationErrorNotACrash(): void
    {
        // What a PHP DateTime becomes in a JSON body: an object, i.e. an array here.
        $e = $this->validateWith(['scheduled_at' => ['date' => '2026-10-18 10:00:00.000000', 'timezone' => 'UTC']]);

        $this->assertSame(['scheduled_at'], array_keys($e->errors()));
    }

    public function testTheMessageNamesTheFieldNotItsValue(): void
    {
        $e = $this->validateWith(['scheduled_at' => 'not-a-date']);

        $message = $e->errors()['scheduled_at'][0];
        $this->assertStringContainsString('scheduled at', $message);
        $this->assertStringNotContainsString('not-a-date', $message);
    }
}
