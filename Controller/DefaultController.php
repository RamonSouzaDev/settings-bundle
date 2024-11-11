<?php

/*
 * This file is part of the Novo SGA project.
 *
 * (c) Rogerio Lino <rogeriolino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Novosga\SettingsBundle\Controller;

use App\Form\PainelUnidadeType;
use Novosga\Entity\PainelUnidade;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Novosga\Entity\Unidade;
use Exception;
use Novosga\Entity\Contador;
use Novosga\Entity\Local;
use Novosga\Entity\Servico;
use Novosga\Entity\ServicoUnidade;
use Novosga\Entity\ServicoUsuario;
use Novosga\Entity\Usuario;
use Novosga\Http\Envelope;
use Novosga\Service\AtendimentoService;
use Novosga\Service\FilaService;
use Novosga\Service\UsuarioService;
use Novosga\Service\ServicoService;
use Novosga\SettingsBundle\Form\ImpressaoType;
use Novosga\SettingsBundle\Form\ServicoUnidadeType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use function array_map;
use function array_filter;

/**
 * DefaultController
 *
 * Controlador do módulo de configuração da unidade
 *
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
class DefaultController extends AbstractController
{
    const DOMAIN = 'NovosgaSettingsBundle';

    /**
     * @param Request $request
     * @return Response
     *
     * @Route("/", name="novosga_settings_index", methods={"GET"})
     */
    public function index(
        Request $request,
        ServicoService $servicoService,
        UsuarioService $usuarioService,
        TranslatorInterface $translator
    ) {
        $em = $this->getDoctrine()->getManager();
        
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        
        // locais disponiveis
        $locais = $em
            ->getRepository(Local::class)
            ->findBy([], ['nome' => 'ASC']);

        // usuarios da unidade
        $todosUsuarios = $em
            ->getRepository(Usuario::class)
            ->findByUnidade($unidade);
        $usuarios = array_values(array_filter($todosUsuarios, function (Usuario $usuario) {
            return $usuario->isAtivo();
        }));

        $servicosUnidade = $servicoService->servicosUnidade($unidade);
        
        $usuariosArray = array_map(function (Usuario $usuario) use (
            $em,
            $unidade,
            $servicosUnidade,
            $usuarioService
        ) {
            $servicosUsuario = $em
                ->getRepository(ServicoUsuario::class)
                ->getAll($usuario, $unidade);
            
            $data  = $usuario->jsonSerialize();
            $data['servicos'] = [];
                    
            foreach ($servicosUnidade as $servicoUnidade) {
                foreach ($servicosUsuario as $servicoUsuario) {
                    $idA = $servicoUsuario->getServico()->getId();
                    $idB = $servicoUnidade->getServico()->getId();
                    
                    if ($idA === $idB) {
                        $data['servicos'][] = [
                            'id'    => $servicoUnidade->getServico()->getId(),
                            'sigla' => $servicoUnidade->getSigla(),
                            'nome'  => $servicoUnidade->getServico()->getNome(),
                            'peso'  => $servicoUsuario->getPeso(),
                        ];
                    }
                }
            }
            
            $tipoMeta                = $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_TIPO);
            $data['tipoAtendimento'] = $tipoMeta ? $tipoMeta->getValue() : FilaService::TIPO_TODOS;

            $localMeta      = $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_LOCAL);
            $data['local']  = $localMeta ? (int) $localMeta->getValue() : null;
            
            $numeroMeta      = $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_NUM_LOCAL);
            $data['numero']  = $numeroMeta ? (int) $numeroMeta->getValue() : null;
            
            return $data;
        }, $usuarios);
        
        $tiposAtendimento = $this->getTiposAtendimento($translator);
        
        $form          = $this->createForm(ServicoUnidadeType::class);
        $inlineForm    = $this->createForm(ServicoUnidadeType::class);
        $impressaoForm = $this->createForm(ImpressaoType::class, $unidade->getImpressao());

         // Get panel config
         $painelConfig = $em
            ->getRepository(PainelUnidade::class)
            ->findOneBy(['unidade' => $unidade, 'deletedAt' => null]);

        if (!$painelConfig) {
            $painelConfig = new PainelUnidade();
            $painelConfig->setUnidade($unidade);
        }

        $painelForm = $this->createForm(PainelUnidadeType::class, $painelConfig);

       // Prepare painelConfig data
        $painelConfigJson = null;
        if ($painelConfig) {
            $painelConfigJson = [
                'id' => $painelConfig->getId(),
                'texto' => $painelConfig->getTexto(),
                'descricao' => $painelConfig->getDescricao(),
                'footer' => $painelConfig->getFooter(),
                'videoUrl' => $painelConfig->getVideoUrl(),
                'image' => ''
            ];

            if ($painelConfig->getImage()) {
                $painelConfigJson['imageUrl'] = $this->generateUrl(
                    'novosga_settings_painel_image',
                    ['id' => $painelConfig->getId()]
                );
            }
        }

        return $this->render('@NovosgaSettings/default/index.html.twig', [
            'usuario'          => $usuario,
            'unidade'          => $unidade,
            'locais'           => $locais,
            'usuarios'         => $usuariosArray,
            'tiposAtendimento' => $tiposAtendimento,
            'form'             => $form->createView(),
            'inlineForm'       => $inlineForm->createView(),
            'impressaoForm'    => $impressaoForm->createView(),
            'painelForm'       => $painelForm->createView(),
            'painelConfig'     => $painelConfigJson,
        ]);
    }
    
    /**
     * @param Request $request
     * @return Response
     *
     * @Route("/servicos", name="novosga_settings_servicos", methods={"GET"})
     */
    public function servicos(Request $request, ServicoService $servicoService)
    {
        $ids = array_filter(explode(',', $request->get('ids')), function ($i) {
            return $i > 0;
        });
        
        if (empty($ids)) {
            $ids = [0];
        }
        
        $servicos = $this
            ->getDoctrine()
            ->getManager()
            ->createQueryBuilder()
            ->select('e')
            ->from(Servico::class, 'e')
            ->where('e.mestre IS NULL')
            ->andWhere('e.deletedAt IS NULL')
            ->andWhere('e.id NOT IN (:ids)')
            ->orderBy('e.nome', 'ASC')
            ->setParameters([
                'ids' => $ids
            ])
            ->getQuery()
            ->getResult();
        
        $envelope = new Envelope();
        $envelope->setData($servicos);
        
        return $this->json($envelope);
    }
    
    /**
     * @param Request $request
     * @return Response
     *
     * @Route("/servicos_unidade", name="novosga_settings_servicos_unidade", methods={"GET"})
     */
    public function servicosUnidade(Request $request, ServicoService $servicoService)
    {
        $usuario  = $this->getUser();
        $unidade  = $usuario->getLotacao()->getUnidade();
        $servicos = $servicoService->servicosUnidade($unidade);
        
        $envelope = new Envelope();
        $envelope->setData($servicos);
        
        return $this->json($envelope);
    }
    
    /**
     * @param Request $request
     * @return Response
     *
     * @Route("/servicos_unidade", name="novosga_settings_add_servico_unidade", methods={"POST"})
     */
    public function addServico(Request $request, ServicoService $servicoService)
    {
        $json    = $request->getContent();
        $data    = json_decode($json, true);
        $ids     = $data['ids'] ?? [];
        $unidade = $this->getUser()->getLotacao()->getUnidade();
        $em      = $this->getDoctrine()->getManager();
        
        if (!is_array($ids)) {
            $ids = [];
        }
        
        $count = count($servicoService->servicosUnidade($unidade));
        
        foreach ($ids as $id) {
            $servico = $em->find(Servico::class, $id);

            if ($servico) {
                $sigla = $this->novaSigla(++$count);

                $su = new ServicoUnidade();
                $su
                    ->setUnidade($unidade)
                    ->setServico($servico)
                    ->setIncremento(1)
                    ->setMensagem('')
                    ->setNumeroInicial(1)
                    ->setPeso(1)
                    ->setTipo(ServicoUnidade::ATENDIMENTO_TODOS)
                    ->setSigla($sigla)
                    ->setAtivo(false);
                
                $contador = $this
                    ->getDoctrine()
                    ->getManager()
                    ->getRepository(Contador::class)
                    ->findOneBy([
                        'unidade' => $unidade,
                        'servico' => $servico,
                    ]);
                
                if (!$contador) {
                    $contador = new Contador();
                    $contador->setServico($servico);
                    $contador->setUnidade($unidade);
                    $contador->setNumero($su->getNumeroInicial());
                    $em->persist($contador);
                } else {
                    $contador->setNumero($su->getNumeroInicial());
                    $em->merge($contador);
                }

                $em->persist($su);
                $em->flush();
            }
        }
        
        $envelope = new Envelope();
        
        return $this->json($envelope);
    }
    
    /**
     * @Route("/servicos_unidade/{id}", name="novosga_settings_remove_servico_unidade", methods={"DELETE"})
     */
    public function removeServicoUnidade(Request $request, Servico $servico, TranslatorInterface $translator)
    {
        $em       = $this->getDoctrine()->getManager();
        $unidade  = $this->getUser()->getLotacao()->getUnidade();
        $envelope = new Envelope();
        
        $su = $em
            ->getRepository(ServicoUnidade::class)
            ->get($unidade, $servico);

        if (!$su) {
            throw new Exception($translator->trans('error.invalid_service', [], self::DOMAIN));
        }

        if ($su->isAtivo()) {
            throw new Exception($translator->trans('error.cannot_remove_disabled_service', [], self::DOMAIN));
        }
        
        $em->transactional(function ($em) use ($su, $unidade, $servico) {
            $em->remove($su);
        
            $em
                ->createQueryBuilder()
                ->delete(Contador::class, 'e')
                ->where('e.unidade = :unidade AND e.servico = :servico')
                ->setParameters([
                    'unidade' => $unidade,
                    'servico' => $servico,
                ])
                ->getQuery()
                ->execute();

            $em
                ->createQueryBuilder()
                ->delete(ServicoUsuario::class, 'e')
                ->where('e.unidade = :unidade AND e.servico = :servico')
                ->setParameters([
                    'unidade' => $unidade,
                    'servico' => $servico,
                ])
                ->getQuery()
                ->execute();
        });
        
        return $this->json($envelope);
    }
    
    /**
     * @param Request $request
     * @return Response
     *
     * @Route("/servicos_unidade/{id}", name="novosga_settings_update_servicos_unidade", methods={"PUT"})
     */
    public function updateServico(Request $request, Servico $servico)
    {
        $json = $request->getContent();
        $data = json_decode($json, true);
        
        $em      = $this->getDoctrine()->getManager();
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        
        $su = $em
            ->getRepository(ServicoUnidade::class)
            ->get($unidade, $servico);
        
        $form = $this->createForm(ServicoUnidadeType::class, $su);
        $form->submit($data);
        
        $em->persist($su);
        $em->flush();
        
        $envelope = new Envelope();
        $envelope->setData($su);
        
        return $this->json($envelope);
    }
    
    /**
     * @param Request $request
     * @return Response
     *
     * @Route("/contadores", name="novosga_settings_contadores", methods={"GET"})
     */
    public function contadores(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        
        $contadores = $em
            ->createQueryBuilder()
            ->select('e')
            ->from(Contador::class, 'e')
            ->join('e.servico', 's')
            ->join(ServicoUnidade::class, 'su', 'WITH', 'su.servico = s')
            ->where('e.unidade = :unidade')
            ->setParameter('unidade', $unidade)
            ->getQuery()
            ->getResult();
        
        $envelope = new Envelope();
        $envelope->setData($contadores);
        
        return $this->json($envelope);
    }

    /**
     * @param Request $request
     * @return Response
     *
     * @Route("/update_impressao", name="novosga_settings_update_impressao", methods={"POST"})
     */
    public function updateImpressao(Request $request)
    {
        $envelope = new Envelope();
        
        $em      = $this->getDoctrine()->getManager();
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();

        $data = json_decode($request->getContent(), true);

        $form = $this->createForm(ImpressaoType::class, $unidade->getImpressao());
        $form->submit($data);

        $em->persist($unidade);
        $em->flush();

        $envelope->setData($unidade);

        return $this->json($envelope);
    }

    /**
     * @param Request $request
     * @return Response
     *
     * @Route("/reiniciar/{id}", name="novosga_settings_reiniciar_contador", methods={"POST"})
     */
    public function reiniciarContador(Request $request, Servico $servico, TranslatorInterface $translator)
    {
        $envelope = new Envelope();
        
        $em = $this->getDoctrine()->getManager();

        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();

        $su = $em
            ->getRepository(ServicoUnidade::class)
            ->get($unidade, $servico);

        if (!$su) {
            throw new Exception($translator->trans('error.invalid_service', [], self::DOMAIN));
        }

        $contador = $em
            ->getRepository(Contador::class)
            ->findOneBy([
                'unidade' => $unidade->getId(),
                'servico' => $servico->getId()
            ]);

        $contador->setNumero($su->getNumeroInicial());
        $em->persist($contador);
        $em->flush();

        $envelope->setData($contador);

        return $this->json($envelope);
    }

    /**
     * @param Request $request
     * @return Response
     *
     * @Route("/limpar", name="novosga_settings_limpar_dados", methods={"POST"})
     */
    public function limparDados(Request $request, AtendimentoService $atendimentoService)
    {
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        
        $atendimentoService->limparDados($unidade);
        
        $envelope = new Envelope();
        $envelope->setData(true);

        return $this->json($envelope);
    }

    /**
     * @param Request $request
     * @return Response
     *
     * @Route("/acumular_atendimentos", name="novosga_settings_acumular_atendimentos", methods={"POST"})
     */
    public function reiniciar(Request $request, AtendimentoService $atendimentoService)
    {
        $envelope = new Envelope();
        $usuario  = $this->getUser();
        $unidade  = $usuario->getLotacao()->getUnidade();

        $atendimentoService->acumularAtendimentos($unidade);

        return $this->json($envelope);
    }
    
    /**
     * @Route("/servico_usuario/{usuarioId}/{servicoId}", name="novosga_settings_add_servico_usuario", methods={"POST"})
     * @ParamConverter("usuario", options={"id" = "usuarioId"})
     * @ParamConverter("servico", options={"id" = "servicoId"})
     */
    public function addServicoUsuario(
        Request $request,
        Usuario $usuario,
        Servico $servico,
        UsuarioService $usuarioService,
        TranslatorInterface $translator
    ) {
        $em       = $this->getDoctrine()->getManager();
        $unidade  = $this->getUser()->getLotacao()->getUnidade();
        $envelope = new Envelope();
        
        $su = $em
            ->getRepository(ServicoUnidade::class)
            ->get($unidade, $servico);

        if (!$su) {
            throw new Exception($translator->trans('error.invalid_service', [], self::DOMAIN));
        }

        $servicoUsuario = $usuarioService->addServicoUsuario($usuario, $servico, $unidade);
        $envelope->setData($servicoUsuario);
        
        return $this->json($envelope);
    }
    
    /**
     * @Route(
     *   "/servico_usuario/{usuarioId}/{servicoId}",
     *   name="novosga_settings_remove_servico_usuario",
     *   methods={"DELETE"}
     * )
     * @ParamConverter("usuario", options={"id" = "usuarioId"})
     * @ParamConverter("servico", options={"id" = "servicoId"})
     */
    public function removeServicoUsuario(
        Request $request,
        Usuario $usuario,
        Servico $servico,
        UsuarioService $usuarioService,
        TranslatorInterface $translator
    ) {
        $em       = $this->getDoctrine()->getManager();
        $unidade  = $this->getUser()->getLotacao()->getUnidade();
        $envelope = new Envelope();
        
        $su = $em
            ->getRepository(ServicoUnidade::class)
            ->get($unidade, $servico);

        if (!$su) {
            throw new Exception($translator->trans('error.invalid_service', [], self::DOMAIN));
        }

        $usuarioService->removeServicoUsuario($usuario, $servico, $unidade);
        
        return $this->json($envelope);
    }
    
    /**
     * @Route(
     *   "/servico_usuario/{usuarioId}/{servicoId}",
     *   name="novosga_settings_update_servico_usuario",
     *   methods={"PUT"}
     * )
     * @ParamConverter("usuario", options={"id" = "usuarioId"})
     * @ParamConverter("servico", options={"id" = "servicoId"})
     */
    public function updateServicoUsuario(
        Request $request,
        Usuario $usuario,
        Servico $servico,
        UsuarioService $usuarioService,
        TranslatorInterface $translator
    ) {
        $em       = $this->getDoctrine()->getManager();
        $unidade  = $this->getUser()->getLotacao()->getUnidade();
        $envelope = new Envelope();
        
        $su = $em
            ->getRepository(ServicoUnidade::class)
            ->get($unidade, $servico);

        if (!$su) {
            throw new Exception($translator->trans('error.invalid_service', [], self::DOMAIN));
        }
        
        $json = json_decode($request->getContent());
        
        if (isset($json->peso) && $json->peso > 0) {
            $servicoUsuario = $usuarioService->updateServicoUsuario($usuario, $servico, $unidade, (int) $json->peso);
            $envelope->setData($servicoUsuario);
        }

        return $this->json($envelope);
    }
    
    /**
     * @Route("/usuario/{id}", name="novosga_settings_update_usuario", methods={"PUT"})
     */
    public function updateUsuario(
        Request $request,
        Usuario $usuario,
        UsuarioService $usuarioService
    ) {
        $json = json_decode($request->getContent());
        $envelope = new Envelope();
        
        $tipoAtendimento = isset($json->tipoAtendimento) ? $json->tipoAtendimento : null;
        $local = isset($json->local) ? (int) $json->local : null;
        $numero = isset($json->numero) ? (int) $json->numero : null;

        $usuarioService->updateUsuario($usuario, $tipoAtendimento, $local, $numero);

        return $this->json($envelope);
    }
    
    private function getTiposAtendimento(TranslatorInterface $translator)
    {
        return [
            FilaService::TIPO_TODOS        => $translator->trans('label.all', [], self::DOMAIN),
            FilaService::TIPO_NORMAL       => $translator->trans('label.normal', [], self::DOMAIN),
            FilaService::TIPO_PRIORIDADE   => $translator->trans('label.priority', [], self::DOMAIN),
            FilaService::TIPO_AGENDAMENTO  => $translator->trans('label.schedule', [], self::DOMAIN),
        ];
    }

    private function novaSigla($c)
    {
        $c = intval($c);

        if ($c <= 0) {
            return '';
        }
    
        $letter = '';
                 
        while ($c != 0) {
           $p      = ($c - 1) % 26;
           $c      = intval(($c - $p) / 26);
           $letter = chr(65 + $p) . $letter;
        }
        
        return $letter;
    }
    
    /**
     * @Route("/update_automatic_call", name="settings_update_automatic_call", methods={"POST"})
     */
    public function updateAutomaticCall(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        /** @var Usuario $usuario */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();

        $data = json_decode($request->getContent(), true);

        $unidade->setAutomaticCallEnabled($data['enabled'] ?? false);
        $unidade->setAutomaticCallInterval($data['interval'] ?? 0);
        $unidade->setUpdatedAt(new \DateTime());

        $em->persist($unidade);
        $em->flush();

        $envelope = new Envelope();
        $envelope->setData([
            'enabled' => $unidade->isAutomaticCallEnabled(),
            'interval' => $unidade->getAutomaticCallInterval(),
        ]);

        return $this->json($envelope);
    }

    /**
     * @Route("/get_automatic_call", name="settings_get_automatic_call", methods={"GET"})
     */
    public function getAutomaticCall()
    {
        /** @var Usuario $usuario */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();

        $envelope = new Envelope();
        $envelope->setData([
            'enabled' => $unidade->isAutomaticCallEnabled(),
            'interval' => $unidade->getAutomaticCallInterval(),
        ]);

        return $this->json($envelope);
    }

    /**
     * @Route("/update_painel", name="novosga_settings_update_painel", methods={"POST"})
     */
    public function updatePainel(Request $request)
    {
        $envelope = new Envelope();
        
        $em      = $this->getDoctrine()->getManager();
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();

        $painelConfig = $em
            ->getRepository(PainelUnidade::class)
            ->findByUnidade($unidade);

        if (!$painelConfig) {
            $painelConfig = new PainelUnidade();
            $painelConfig->setUnidade($unidade);
        }

        $form = $this->createForm(PainelUnidadeType::class, $painelConfig);
        $form->handleRequest($request);

        // Atualizar dados básicos
        $painelConfig->setTexto($request->request->get('texto'));
        $painelConfig->setDescricao($request->request->get('descricao'));
        $painelConfig->setFooter($request->request->get('footer'));
        $painelConfig->setVideoUrl($request->request->get('videoUrl'));
        
        // Atualizar o layout selecionado
        $selectedLayout = $request->request->get('layout_tipo');
        $painelConfig->setSelectedLayout($selectedLayout);

        // Atualizar a data de modificação
        $painelConfig->setUpdatedAt(new \DateTime());
        
        // Verifica e processa a imagem
        /** @var UploadedFile|null $file */
        $file = $request->files->get('image');
        if ($file) {
            $imageContent = file_get_contents($file->getPathname());
            if ($imageContent !== false) {
                $painelConfig->setImage($imageContent);
            }
        }
        
        // Persistência
        $em->persist($painelConfig);
        $em->flush();
        
        // Preparar dados de retorno
        $responseData = [
            'id' => $painelConfig->getId(),
            'texto' => $painelConfig->getTexto(),
            'descricao' => $painelConfig->getDescricao(),
            'footer' => $painelConfig->getFooter(),
            'videoUrl' => $painelConfig->getVideoUrl(),
            'selectedLayout' => $painelConfig->getSelectedLayout() // Incluir o layout selecionado na resposta
        ];

        if ($painelConfig->getImage()) {
            $responseData['imageUrl'] = $this->generateUrl(
                'novosga_settings_painel_image',
                ['id' => $painelConfig->getId()]
            );
        }

        $envelope->setData($responseData);
        $envelope->setSuccess(true);
        $envelope->setMessage('Configurações do painel atualizadas com sucesso');

        return $this->json($envelope);
    }

    /**
     * @Route("/update_layout", name="novosga_settings_update_layout", methods={"POST"})
     */
    public function updateLayout(Request $request)
    {
        $envelope = new Envelope();
        
        try {
            // Debug dos dados recebidos
            $content = $request->getContent();
            error_log('Raw request content: ' . $content);
            
            $allData = $request->request->all();
            error_log('All request data: ' . print_r($allData, true));

            // Tentar pegar os dados de diferentes formas
            $selectedLayout = $request->request->get('selectedLayout');
            $unidadeId = $request->request->get('unidadeId');
            
            error_log('Selected Layout: ' . var_export($selectedLayout, true));
            error_log('Unidade ID: ' . var_export($unidadeId, true));

            // Se os dados não estiverem no request->request, tentar do conteúdo JSON
            if (!$selectedLayout || !$unidadeId) {
                $jsonData = json_decode($content, true);
                if ($jsonData) {
                    $selectedLayout = $jsonData['selectedLayout'] ?? null;
                    $unidadeId = $jsonData['unidadeId'] ?? null;
                    error_log('Data from JSON: ' . print_r($jsonData, true));
                }
            }

            if (!$selectedLayout || !$unidadeId) {
                throw new \InvalidArgumentException('Dados inválidos ou incompletos');
            }

            $em = $this->getDoctrine()->getManager();
            $unidade = $em->getRepository(Unidade::class)->find($unidadeId);
            
            if (!$unidade) {
                throw new \Exception("Unidade não encontrada: $unidadeId");
            }

            $painelConfig = $em
                ->getRepository(PainelUnidade::class)
                ->findOneBy(['unidade' => $unidade]);

            if (!$painelConfig) {
                $painelConfig = new PainelUnidade();
                $painelConfig->setUnidade($unidade);
            }

            $painelConfig->setSelectedLayout((string)$selectedLayout);
            $painelConfig->setUpdatedAt(new \DateTime());
            
            $em->persist($painelConfig);
            $em->flush();

            $responseData = [
                'id' => $painelConfig->getId(),
                'selectedLayout' => $painelConfig->getSelectedLayout(),
                'unidadeId' => $unidade->getId()
            ];

            $envelope->setSuccess(true);
            $envelope->setData($responseData);
            $envelope->setMessage('Layout atualizado com sucesso');

        } catch (\Exception $e) {
            error_log('Error in updateLayout: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            $envelope->setSuccess(false);
            $envelope->setMessage('Erro ao atualizar layout: ' . $e->getMessage());
        }

        return $this->json($envelope);
    }

    /**
     * @Route("/painel/image/{id}", name="novosga_settings_painel_image", methods={"GET"})
     */
    public function getPainelImage(PainelUnidade $painelConfig)
    {
        $image = $painelConfig->getImage();
        if (!$image) {
            throw $this->createNotFoundException('Imagem não encontrada');
        }

        // Se for um resource, converte para string
        if (is_resource($image)) {
            $image = stream_get_contents($image);
        }

        $response = new Response($image);
        $response->headers->set('Content-Type', 'image/png');
        $response->setPublic();
        $response->setMaxAge(3600);
        
        return $response;
    }

/**
 * @Route("/painel/layouts", name="novosga_settings_painel_layouts", methods={"GET"})
 */
public function getPainelLayouts(Request $request)
{
    try {
        $em = $this->getDoctrine()->getManager();
        
        // Pegar unidade do request
        $unidadeId = $request->query->get('unidadeId');
        if (!$unidadeId) {
            throw new \InvalidArgumentException('ID da unidade é obrigatório');
        }

        // Buscar a unidade
        $unidade = $em->getRepository(Unidade::class)->find($unidadeId);
        if (!$unidade) {
            throw new \Exception('Unidade não encontrada');
        }

        // Buscar configuração do painel da unidade
        $painelConfig = $em
            ->getRepository(PainelUnidade::class)
            ->findOneBy(['unidade' => $unidade, 'deletedAt' => null]);

        // Verificar disponibilidade de mídias
        $hasImage = $painelConfig && $painelConfig->getImage() !== null;
        $hasVideo = $painelConfig && $painelConfig->getVideoUrl() !== null;
        $hasText = $painelConfig && $painelConfig->getTexto() !== null;
        $hasDescription = $painelConfig && $painelConfig->getDescricao() !== null;
        $hasFooter = $painelConfig && $painelConfig->getFooter() !== null;

        // Lista de layouts com validações completas
        $layouts = [
            [
                'id' => 'layout1',
                'name' => 'Layout 1',
                'description' => 'Layout com texto, descrição, imagem e rodapé',
                'preview' => '/bundles/novosgasettings/images/layout1.png',
                'available' => $hasImage && $hasText && $hasDescription && $hasFooter,
                'requires' => [
                    'texto' => $hasText,
                    'descricao' => $hasDescription,
                    'image' => $hasImage,
                    'footer' => $hasFooter
                ],
                'missingRequirements' => $this->getMissingRequirements($hasImage, $hasText, $hasDescription, $hasFooter),
                'selected' => $painelConfig ? $painelConfig->getSelectedLayout() === 'layout1' : false
            ],
            [
                'id' => 'layout2',
                'name' => 'Layout 2',
                'description' => 'Layout com vídeo',
                'preview' => '/bundles/novosgasettings/images/layout2.png',
                'available' => $hasVideo,
                'requires' => [
                    'video_url' => $hasVideo
                ],
                'missingRequirements' => $hasVideo ? [] : ['video_url'],
                'selected' => $painelConfig ? $painelConfig->getSelectedLayout() === 'layout2' : false
            ],
            [
                'id' => 'layout3',
                'name' => 'Layout 3',
                'description' => 'Layout com texto e descrição',
                'preview' => '/bundles/novosgasettings/images/layout3.png',
                'available' => $hasText && $hasDescription,
                'requires' => [
                    'texto' => $hasText,
                    'descricao' => $hasDescription
                ],
                'missingRequirements' => $this->getMissingRequirements($hasText, $hasDescription),
                'selected' => $painelConfig ? $painelConfig->getSelectedLayout() === 'layout3' : false
            ],
            [
                'id' => 'layout4',
                'name' => 'Layout 4',
                'description' => 'Layout padrão',
                'preview' => '/bundles/novosgasettings/images/layout4.png',
                'available' => true,
                'requires' => [],
                'missingRequirements' => [],
                'selected' => $painelConfig ? $painelConfig->getSelectedLayout() === 'layout4' : false
            ]
        ];

        // Preparar configuração atual
        $currentConfig = null;
        if ($painelConfig) {
            $currentConfig = [
                'id' => $painelConfig->getId(),
                'texto' => $painelConfig->getTexto(),
                'descricao' => $painelConfig->getDescricao(),
                'footer' => $painelConfig->getFooter(),
                'video_url' => $painelConfig->getVideoUrl(),
                'image' => $hasImage,
                'imageUrl' => $hasImage ? $this->generateUrl('novosga_settings_painel_image', ['id' => $painelConfig->getId()]) : null,
                'selected_layout' => $painelConfig->getSelectedLayout()
            ];
        }

        $envelope = new Envelope();
        $envelope->setData([
            'layouts' => $layouts,
            'currentLayout' => $painelConfig ? $painelConfig->getSelectedLayout() : null,
            'unidadeInfo' => [
                'id' => $unidade->getId(),
                'hasImage' => $hasImage,
                'hasVideo' => $hasVideo,
                'hasText' => $hasText,
                'hasDescription' => $hasDescription,
                'hasFooter' => $hasFooter
            ],
            'currentConfig' => $currentConfig
        ]);

        return $this->json($envelope);
        
    } catch (\Exception $e) {
        $envelope = new Envelope();
        $envelope->setSuccess(false);
        $envelope->setMessage($e->getMessage());
        return $this->json($envelope);
    }
}

/**
 * Helper method to get missing requirements
 */
private function getMissingRequirements(...$requirements)
{
    $missing = [];
    $fields = ['image', 'texto', 'descricao', 'footer', 'video_url'];
    foreach ($requirements as $index => $hasRequirement) {
        if (!$hasRequirement && isset($fields[$index])) {
            $missing[] = $fields[$index];
        }
    }
    return $missing;
}

}
