<?php

/*
 * This file is part of the Novo SGA project.
 *
 * (c) Rogerio Lino <rogeriolino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Novosga\SettingsBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Novosga\Entity\PainelUnidade;
use Novosga\Entity\Unidade;
use Novosga\Repository\PainelUnidadeRepositoryInterface;

/**
 * PainelUnidadeRepository
 *
 * @author Rogério Lino <rogeriolino@gmail.com>
 */
class PainelUnidadeRepository extends ServiceEntityRepository implements PainelUnidadeRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PainelUnidade::class);
    }

    /**
     * {@inheritdoc}
     */
    public function findByUnidade(Unidade $unidade)
    {
        return $this->findOneBy([
            'unidade' => $unidade,
            'deletedAt' => null
        ]);
    }
}