<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fewohbee\PaymentCore\Entity\PaymentWebhookInbox;

/** @extends ServiceEntityRepository<PaymentWebhookInbox> */
final class PaymentWebhookInboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, PaymentWebhookInbox::class); }

    /** @return PaymentWebhookInbox[] */
    public function findDue(\DateTimeImmutable $now, int $limit = 200): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.processedAt IS NULL')
            ->andWhere('w.nextAttemptAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('w.nextAttemptAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
