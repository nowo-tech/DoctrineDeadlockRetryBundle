<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DemoNote;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DemoController extends AbstractController
{
    public function __construct(
        private readonly DeadlockRetryService $deadlockRetry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/', name: 'demo_home')]
    public function home(): Response
    {
        $note = new DemoNote('Demo note ' . date('Y-m-d H:i:s'));
        $this->entityManager->persist($note);
        $this->deadlockRetry->flush();

        $notes = $this->entityManager->getRepository(DemoNote::class)->findBy(
            [],
            ['id' => 'DESC'],
            10,
        );

        return $this->render('demo/home.html.twig', [
            'version_badge' => 'Symfony 8.0',
            'latest_note'   => $note,
            'notes'         => $notes,
            'profiles'      => ['default', 'batch'],
        ]);
    }
}
