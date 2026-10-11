<?php

namespace LBHurtado\ElectionCeremonies\Contracts;

use Closure;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyContext;

interface CeremonyPipe
{
    /** @param Closure(CeremonyContext): CeremonyContext $next */
    public function handle(CeremonyContext $context, Closure $next): CeremonyContext;
}
