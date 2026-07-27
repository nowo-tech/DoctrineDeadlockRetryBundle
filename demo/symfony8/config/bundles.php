<?php

declare(strict_types=1);
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\DoctrineDeadlockRetryBundle\NowoDoctrineDeadlockRetryBundle;
use Nowo\TwigInspectorBundle\NowoTwigInspectorBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;

return [
    FrameworkBundle::class                 => ['all' => true],
    TwigBundle::class                      => ['all' => true],
    WebProfilerBundle::class               => ['dev' => true, 'test' => true],
    DoctrineBundle::class                  => ['all' => true],
    NowoDoctrineDeadlockRetryBundle::class => ['all' => true],
    NowoTwigInspectorBundle::class         => ['dev' => true, 'test' => true],
];
