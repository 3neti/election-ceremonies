<?php

namespace LBHurtado\ElectionCeremonies\Contracts;

use LBHurtado\ElectionCeremonies\Ceremony\CeremonyRequest;

interface ActorVerifier
{
    public function verify(CeremonyRequest $request): void;
}
