<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Repository;

use Fewohbee\PaymentCore\Entity\PaymentTransaction;
use Fewohbee\PaymentCore\Enum\PaymentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaymentTransaction>
 */
class PaymentTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentTransaction::class);
    }

    public function findOneByProviderAndProviderPaymentId(string $providerId, string $providerPaymentId): ?PaymentTransaction
    {
        return $this->findOneBy([
            'providerId' => $providerId,
            'providerPaymentId' => $providerPaymentId,
        ]);
    }

    /** @return PaymentTransaction[] */
    public function findByExternalReference(string $externalReference): array
    {
        return $this->findBy(['externalReference' => $externalReference], ['createdAt' => 'ASC']);
    }

    /** @return PaymentTransaction[] */
    public function findPending(int $limit = 200): array
    {
        return $this->findDue(new \DateTimeImmutable(), $limit);
    }

    /**
     * Fair work queue: every successful or failed poll moves nextCheckAt into
     * the future, so a permanently broken oldest row cannot starve newer rows.
     * Settled payments stay in the queue at a lower audit cadence to discover
     * later reversals/chargebacks even when the provider has no such webhook.
     *
     * @return PaymentTransaction[]
     */
    public function findDue(\DateTimeImmutable $now, int $limit = 200): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status IN (:statuses)')
            ->andWhere('p.nextCheckAt IS NULL OR p.nextCheckAt <= :now')
            ->setParameter('statuses', [
                PaymentStatus::PENDING,
                PaymentStatus::INITIATED,
                PaymentStatus::SETTLED,
                PaymentStatus::FAILED,
                PaymentStatus::REFUND_PENDING,
            ])
            ->setParameter('now', $now)
            ->orderBy('p.nextCheckAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
