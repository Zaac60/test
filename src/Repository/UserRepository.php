<?php

namespace App\Repository;

use App\Document\NewsletterFrequencyOptions;
use Doctrine\ODM\MongoDB\Repository\DocumentRepository;

/**
 * AboutRepository.
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class UserRepository extends DocumentRepository
{
    public function findNeedsToReceiveNewsletter()
    {
        $qb = $this->query('User');

        return $qb->field('newsletterFrequency')->gt(NewsletterFrequencyOptions::Never)
                ->field('nextNewsletterDate')->lte(new \DateTime())
                ->limit(70)
                ->getQuery()->execute();
    }
}
