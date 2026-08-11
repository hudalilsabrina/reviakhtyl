<?php

namespace App\Traits\Services;

use App\Exceptions\Service\Egg\Variable\BadValidationRuleException;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Str;

trait ValidatesValidationRules
{
    abstract protected function getValidator(): ValidationFactory;

    /**
     * Validate that the rules being provided are valid for Laravel and can
     * be resolved.
     *
     * @throws BadValidationRuleException
     */
    public function validateRules(array|string $rules): void
    {
        try {
            $this->getValidator()->make(['__TEST' => 'test'], ['__TEST' => $rules])->fails();
        } catch (\BadMethodCallException $exception) {
            // Laravel >= 10 throws "Method Illuminate\Validation\Validator::validateNotarealrule
            // does not exist." for unknown rules; older versions wrapped the method in brackets.
            $matches = [];
            if (preg_match('/Method [\w\\\\]+::validate(\w+) does not exist\./', $exception->getMessage(), $matches)) {
                throw new BadValidationRuleException(trans('exceptions.nest.variables.bad_validation_rule', ['rule' => Str::snake(array_get($matches, 1, 'unknownRule'))]), $exception);
            }

            throw $exception;
        } catch (\ErrorException $exception) {
            // An unparsable rule (e.g. a regex with no closing delimiter) surfaces
            // as a PHP warning from the underlying rule — surface it the same way.
            throw new BadValidationRuleException(trans('exceptions.nest.variables.bad_validation_rule', ['rule' => 'regex']), $exception);
        }
    }
}
