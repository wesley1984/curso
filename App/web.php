<?php

namespace Source\App;

use Source\Core\Controller;
use Source\Models\Auth;
use Source\Models\Category;
use Source\Models\Faq\Question;
use Source\Models\Filiados\Academias;
use Source\Models\Filiados\Certificados;
use Source\Models\Filiados\Galery;
use Source\Models\Filiados\Karatecas;
use Source\Models\Filiados\Ranking;
use Source\Models\Finan\Empresa;
use Source\Models\Post;
use Source\Models\Report\Access;
use Source\Models\Report\Online;
use Source\Models\User;
use Source\Support\Email;
use Source\Support\Pager;

/**
 * Web Controller
 * @package Source\App
 */
class Web extends Controller
{
    /**
     * Web constructor.
     */
    public function __construct()
    {
        parent::__construct(__DIR__ . "/../../themes/" . CONF_VIEW_THEME . "/");

        (new Access())->report();
        (new Online())->report();

    }

    /**
     * SITE HOME
     */
    public function home(): void
    {

        $get_campeonato = (new Post())->findJoin("category = '2' AND camStatus = '1' AND dtevento >= NOW() AND del = '0'",
            "",
            "posts.*,
                    cidades.cidNome,
                    (SELECT COUNT(ranking.cam_id) FROM ranking WHERE ranking.cam_id = posts.id) AS alunos_inscritos",
            "LEFT JOIN cidades ON cidades.cidId = posts.cidade
        ");

        $galerys = (new Galery())->find("id_of IS NULL", "", "galery.*, (SELECT COUNT(gal.id) FROM galery as gal WHERE gal.id_of = galery.id) AS qtd_fotos");

        $head = $this->seo->render(
            CONF_SITE_NAME. " - " . CONF_SITE_TITLE,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg"),
            true
        );
        echo $this->view->render("home", [
            "head" => $head,
            "video" => "sHBL_9x9vuA?start=41",
            "blog" => (new Post())->findPost()->order("post_at DESC")->limit(6)->fetch(true),
            "campeonato" => $get_campeonato->order("dtevento ASC")->limit(3)->fetch(true),
            "galerys" => $galerys->order("rand()")->limit(8)->fetch(true),
            "whats" => (new Empresa())->find()->fetch()
        ]);
    }

    /**
     * SITE ABOUT
     */
    public function about(): void
    {
        $head = $this->seo->render(
            "Descubra o " . CONF_SITE_NAME . " - " . CONF_SITE_DESC,
            CONF_SITE_DESC,
            url("/sobre"),
            theme("/assets/images/share.jpg"),
            true
        );

        echo $this->view->render("about", [
            "head" => $head,
            "video" => "sHBL_9x9vuA?start=41",
            "faq" => (new Question())
                ->find("channel_id = :id", "id=1", "question, response")
                ->order("order_by")
                ->fetch(true)
        ]);
    }

    /**
     * SITE BLOG
     * @param array|null $data
     */
    public function blog(?array $data): void
    {
        $head = $this->seo->render(
            "Blog - " . CONF_SITE_NAME,
            "Confira em nosso blog dicas e sacadas de como controlar melhorar suas contas. Vamos tomar um café?",
            url("/blog"),
            theme("/assets/images/share.jpg"),
            true
        );

        $blog = (new Post())->findPost();
        $pager = new Pager(url("/blog/p/"));
        $pager->pager($blog->count(), 21, ($data['page'] ?? 1));

        echo $this->view->render("blog", [
            "head" => $head,
            "blog" => $blog->order("post_at DESC")->limit($pager->limit())->offset($pager->offset())->fetch(true),
            "paginator" => $pager->render()
        ]);
    }

    /**
     * SITE BLOG CATEGORY
     * @param array $Data
     */
    public function blogCategory(array $data): void
    {
        $categoryUri = filter_var($data["category"], FILTER_SANITIZE_STRIPPED);
        $category = (new Category())->findByUri($categoryUri);

        if (!$category) {
            redirect("/blog");
        }

        $blogCategory = (new Post())->findPost("category = :c", "c={$category->id}");
        $page = (!empty($data['page']) && filter_var($data['page'], FILTER_VALIDATE_INT) >= 1 ? $data['page'] : 1);
        $pager = new Pager(url("/blog/em/{$category->uri}/"));
        $pager->pager($blogCategory->count(), 9, $page);

        $head = $this->seo->render(
            "Artigos em {$category->title} - " . CONF_SITE_NAME,
            $category->description,
            url("/blog/em/{$category->uri}/{$page}"),
            ($category->cover ? image($category->cover, 1200, 628) : theme("/assets/images/share.jpg")),
            true
        );

        echo $this->view->render("blog", [
            "head" => $head,
            "title" => "Artigos em {$category->title}",
            "desc" => $category->description,
            "blog" => $blogCategory
                ->limit($pager->limit())
                ->offset($pager->offset())
                ->order("post_at DESC")
                ->fetch(true),
            "paginator" => $pager->render()
        ]);
    }

    /**
     * @param array $data
     */
    public function blogSearch(array $data): void
    {
        if(!empty($data['s'])){
            $search = str_search($data['s']);
            echo json_encode(["redirect" => url("/blog/buscar/{$search}/1")]);
            return;
        }

        $search = str_search($data['search']);
        $page = (filter_var($data['page'], FILTER_VALIDATE_INT) >= 1 ? $data['page'] : 1);

        if($search == "all"){
            redirect("/blog");
        }

        $head = $this->seo->render(
            "Pesquisa por {$search} - " . CONF_SITE_NAME,
            "Confira os resultados de sua pesquisa para {$search}",
            url("/blog/buscar/{$search}/{$page}"),
            theme("/assets/images/share.jpg"),
            true
        );

        $blogSearch = (new Post())->findPost("(title LIKE :s OR subtitle LIKE :s)", "s=%{$search}%");
//        $blogSearch = (new Post())->findPost("MATCH(title, subtitle) AGAINST(:s)", "s={$search}");
        if(!$blogSearch->count()){
            echo $this->view->render("blog", [
                "head" => $head,
                "title" => "Pesquisa por: ",
                "search" => $search
            ]);
            return;
        }

        $pager = new Pager(url("/blog/buscar/{$search}/"));
        $pager->pager($blogSearch->count(), 12, $page);

        echo $this->view->render("blog",[
            "head" => $head,
            "title" => "Pesquisa por: ",
            "search" => $search,
            "blog" => $blogSearch->limit($pager->limit())->offset($pager->offset())->fetch(true),
            "paginator" => $pager->render()
        ]);
    }

    /**
     * SITE BLOG POST
     * @param array $data
     */
    public function blogPost(array $data): void
    {
        $post = (new Post())->findByUri($data['uri']);
        if(!$post){
            redirect("/404");
        }
        $user = Auth::user();
        if(!$user || $user->level < 5){
            $post->view += 1;
            $post->save();
        }

        $head = $this->seo->render(
            $post->title ." - " . CONF_SITE_NAME,
            $post->subtitle,
            url("/blog/{$post->uri}"),
            ($post->cover ? image($post->cover, "1200", "628") : theme("/assets/images/share.jpg")),
            true
        );

        echo $this->view->render("blog-post", [
            "head" => $head,
            "post" => $post,
            "related" => (new Post())
                ->findPost("category = :c AND id != :i", "c={$post->category}&i={$post->id}")
                ->order("rand()")
                ->limit(3)
                ->fetch(true)
        ]);
    }


    /**
     * @param array|null $data
     */
    public function galerias(?array $data): void
    {
        $galerias = (new Galery())->findJoin("id_of IS NULL", "", " 
        galery.*,
        usuario.name as autho_name,
        (SELECT COUNT(gal.id) FROM galery as gal WHERE gal.id_of = galery.id) AS qtd_fotos",
            "LEFT JOIN usuario ON usuario.id = galery.author")->order("rand()")->fetch(true);


        $head = $this->seo->render(
            "Galerias de Fotos Federação de Karate Do Tocantins - " . CONF_SITE_NAME,
            "Confira noas galerias de fotos?",
            url("/galery"),
            theme("/assets/images/share.jpg"),
            true
        );


        echo $this->view->render("galerias", [
            "head" => $head,
            "galerias" => $galerias
        ]);
    }


    /**
     * @param array $data
     */
    public function galery(array $data): void
    {
        $post = (new Galery())->findJoin("uri = :uri", "uri={$data['uri']}", "galery.*,
        usuario.name as autho_name, usuario.photo,
        (SELECT COUNT(gal.id) FROM galery as gal WHERE gal.id_of = galery.id) AS qtd_fotos",
            "LEFT JOIN usuario ON usuario.id = galery.author")->fetch();
        if(!$post){
            redirect("/404");
        }

        $galerias = (new Galery())->find("id_of = :id", "id={$post->id}")->order("rand()")->fetch(true);

        $post->view += 1;

        $head = $this->seo->render(
            $post->title ." - " . CONF_SITE_NAME,
            $post->subtitle,
            url("/galery/{$post->uri}"),
            ($post->cover ? image($post->cover, "1200", "628") : theme("/assets/images/share.jpg")),
            true
        );

        echo $this->view->render("galery", [
            "head" => $head,
            "post" => $post,
            "galerias" => $galerias,
            "related" => (new Galery())->find("category = :c AND id_of IS NULL", "c={$post->category}")->order("rand()")->limit(3)->fetch(true)
        ]);
    }


    /**
     *
     */
    public function contato()
    {
        $head = $this->seo->render(
            "Contate-nos por aqui - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/contato"),
            theme("/assets/images/share.jpg"),
            true
        );

        echo $this->view->render("contact", [
            "head" => $head
        ]);
    }

    /**
     *
     */
    public function professor()
    {
        //AND (dtfim <= NOW() OR status <> 1)
        $professores = (new User())->findJoin("level = '1' AND dtfim > NOW() AND status = 1","","
        usuario.id, usuario.name, usuario.sobrenome, usuario.photo, usuario.email, usuario.created_at, usuario.dtfim, usuario.status, usuario.faixa,
        faixa.faixaNome, 
        (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id AND dtfim >= NOW()) AS alunos_ativo,
        (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id AND (dtfim <= NOW() OR status <> 1)) AS alunos_inativo,
        (SELECT COUNT(academia.user_id) FROM academia WHERE academia.user_id = usuario.id) AS total_academia
        ",
            "LEFT JOIN faixa ON faixa.faixaId = usuario.faixa")->order("alunos_ativo DESC, photo DESC, usuario.name ASC, usuario.photo desc")->fetch(true);

//        var_dump($professores);

        $head = $this->seo->render(
            "Professores filiados e aptos junto a FEKTO - " . CONF_SITE_NAME,
            "Professores registrados na FEKTO no estado do Tocantins, Aptos e licenciados pela Federação Tocantinense de Karate do Tocantins",
            url("/professores"),
            theme("/assets/images/imgpaginas/professores.jpg"),
            true
        );
        echo $this->view->render("pages/professores", [
            "head" => $head,
            "professores" => $professores
        ]);
    }


    /**
     *
     */
    public function academias()
    {
        $academias = (new Academias())->findJoin("", "",
            "academia.*,
            user.name, user.sobrenome, dtfim, status,
            cidades.cidNome,
            (SELECT COUNT(karateca.aca_id) FROM karateca WHERE karateca.aca_id = academia.id AND dtfim > NOW()) AS alunos_ativo,
            (SELECT COUNT(karateca.aca_id) FROM karateca WHERE karateca.aca_id = academia.id) AS alunos_total
            ",
            "
            LEFT JOIN usuario AS user ON user.id = academia.user_id
            LEFT JOIN cidades ON cidades.cidId = academia.acaCidade
            ")->fetch(true);

//        var_dump($academias);

        $head = $this->seo->render(
            "Visualizar todas as nossos academias filiadas na FEKTO - " . CONF_SITE_NAME,
            "Academias e Associações registradas na FEKTO, licenciadas e fiscalizadas pela Federação Tocantinense de Karate do Tocantins",
            url("/academias"),
            theme("/assets/images/imgpaginas/professores.jpg"),
            true
        );
        echo $this->view->render("pages/academias", [
            "head" => $head,
            "academias" => $academias
        ]);
    }


    /**
     * @param array|null $data
     */
    public function diplomas(?array $data): void
    {
        $get_dibploma = null;
        $title = null;;
        $search = null;

        if(!empty($data["action"]) && $data["action"] == "lerDiplomas") {
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

            if(!csrf_verify($data)){
                $this->message->info("Erro ao Enviar, Favor use o formulário...")->flash();
                redirect("/diplomas");
            }
            if(in_array("", $data)){
                $this->message->info("Preencha corretamente o campo Número diploma, Obrigado...")->flash();
                redirect("/diplomas");
            }

            if(!empty($data['dados'])){
                $get_dibploma = (new Certificados())->findJoin("certAutentica = :id", "id={$data['dados']}", "
                certificado.*,
                faixa.faixaNome,
                academia.acaNome,
                usuario.name, usuario.sobrenome, usuario.dtFim
                ",
                    "LEFT JOIN faixa ON faixa.faixaId = certificado.faixaId
                    LEFT JOIN academia ON academia.id = certificado.aca_id
                    LEFT JOIN usuario ON usuario.id = certificado.user_id")->fetch(true);

                if(!$get_dibploma){
                    $this->message->error("A pesquisa não retornou resultado...")->flash();
                    redirect("/diplomas");
                }
                $title = "Pesquisa por: {$data["dados"]}";
                $search = "Confira os dados do diploma Nº Autenticação <b> {$data["dados"]}</b>";
            }
        }

        $head = $this->seo->render(
            "Pesquisar Autenticação de Diploma - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/diplomas"),
            theme("/assets/images/share.jpg"),
            true
        );
        echo $this->view->render("pages/diplomas", [
            "head" => $head,
            "get_diplomas" => $get_dibploma,
            "title" => $title,
            "search" => $search
        ]);
    }


    /**
     * @param array|null $data
     */
    public function verKaratecas(?array $data): void
    {
        $get_karateca = null;
        $get_diplomas = null;
        $get_eventos = null;

        $title = null;;
        $search = null;
        if(!empty($data["action"]) && $data["action"] == "lerKarateca") {
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

            if(!csrf_verify($data)){
                $this->message->info("Erro ao Enviar, Favor use o formulário...")->flash();
                redirect("/verKaratecas");
            }
            if(in_array("", $data)){
                $this->message->info("Preencha corretamente o campo com o Registro FEKTO do atleta, Obrigado...")->flash();
                redirect("/verKaratecas");
            }

            //AND karateca.dtfim > NOW()
            if(!empty($data['dados'])){
                $get_karateca = (new Karatecas())->findJoin("karateca.id = :id", "id={$data['dados']}",
                    "karateca.*,
                faixa.faixaNome,
                academia.acaNome,
                usuario.name, usuario.sobrenome",
                    "LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id
                    LEFT JOIN academia ON academia.id = karateca.aca_id
                    LEFT JOIN usuario ON usuario.id = karateca.user_id")->fetch(true);

                if(!$get_karateca){
                    $this->message->warning("A pesquisa não retornou resultado ou Inativo/excluido...")->flash();
                    redirect("/verKaratecas");
                }else{
                    $get_eventos = (new Ranking())->findJoin("alu_id = :id", "id={$data['dados']}")->fetch(true);

                    $get_diplomas = (new Certificados())->findJoin("alu_id = :id", "id={$data['dados']}",
                        "certificado.*,
                faixa.faixaNome",
                        "LEFT JOIN faixa ON faixa.faixaId = certificado.faixaId")->fetch(true);
                }
                $title = "Pesquisa por: {$data["dados"]}";
                $search = "Confira o status do Aluno ID FEKTO Nº <b> {$data["dados"]}</b>";
            }
        }

        $head = $this->seo->render(
            "Visualizar todos nossos professores filiados - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/professores"),
            theme("/assets/images/share.jpg"),
            true
        );
        echo $this->view->render("pages/infor_karatecas", [
            "head" => $head,
            "get_karateca" => $get_karateca,
            "get_diplomas" => $get_diplomas,
            "get_eventos" => $get_eventos,
            "title" => $title,
            "search" => $search
        ]);
    }


    /**
     * SITE LOGIN
     * @param null|array $data
     */
    public function login(?array $data): void
    {
        if(Auth::user()){
            redirect("/app");
        }

        if (!empty($data['csrf'])) {
            if (!csrf_verify($data)) {
                $json['message'] = $this->message->error("Erro ao enviar, favor use o formulário")->render();
                echo json_encode($json);
                return;
            }

            if (request_limit("weblogin", 7, 60 * 5)) {
                $json['message'] = $this->message->error("Você já efetuou 3 tentativas, esse é o limite. Por favor, aguarde 5 minutos para tentar novamente!")->render();
                echo json_encode($json);
                return;
            }

            if (empty($data['email']) || empty($data['password'])) {
                $json['message'] = $this->message->warning("Informe seu email e senha para entrar")->render();
                echo json_encode($json);
                return;
            }

            $save = (!empty($data['save']) ? true : false);
            $auth = new Auth();
            $login = $auth->login($data['email'], $data['password'], $save);

            if ($login) {
                $this->message->success("Seja bem vindo(a) de volta ". Auth::user()->first_name . "!")->flash();
                $json['redirect'] = url("/app");
            } else {
                $json['message'] = $auth->message()->before("Ooops! ")->render();
            }

            echo json_encode($json);
            return;
        }

        $head = $this->seo->render(
            "Entrar - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/entrar"),
            theme("/assets/images/share.jpg"),
            true
        );

        echo $this->view->render("auth-login", [
            "head" => $head,
            "cookie" => filter_input(INPUT_COOKIE, "authEmail")
        ]);
    }

    /**
     * SITE PASSWORD FORGET
     * @param null|array $data
     */
    public function forget(?array $data)
    {
        if(Auth::user()){
            redirect("/app");
        }

        if (!empty($data['csrf'])) {
            if (!csrf_verify($data)) {
                $json['message'] = $this->message->error("Erro ao enviar, favor use o formulário")->render();
                echo json_encode($json);
                return;
            }

            if (empty($data["email"])) {
                $json['message'] = $this->message->info("Informe seu e-mail para continuar")->render();
                echo json_encode($json);
                return;
            }

            if (request_repeat("webforget", $data["email"])) {
                $json['message'] = $this->message->error("Ooops! Você já tentou este e-mail antes")->render();
                echo json_encode($json);
                return;
            }

            $auth = new Auth();
            if ($auth->forget($data["email"])) {
                $json["message"] = $this->message->success("Acesse seu e-mail para recuperar a senha")->render();
            } else {
                $json["message"] = $auth->message()->before("Ooops! ")->render();
            }

            echo json_encode($json);
            return;
        }

        $head = $this->seo->render(
            "Recuperar Senha - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/recuperar"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("auth-forget", [
            "head" => $head
        ]);
    }

    /**
     * SITE FORGET RESET
     * @param array $data
     */
    public function reset(array $data): void
    {
        if(Auth::user()){
            redirect("/app");
        }

        if (!empty($data['csrf'])) {
            if (!csrf_verify($data)) {
                $json['message'] = $this->message->error("Erro ao enviar, favor use o formulário")->render();
                echo json_encode($json);
                return;
            }

            if (empty($data["password"]) || empty($data["password_re"])) {
                $json["message"] = $this->message->info("Informe e repita a senha para continuar")->render();
                echo json_encode($json);
                return;
            }

            list($email, $code) = explode("|", $data["code"]);
            $auth = new Auth();

            if ($auth->reset($email, $code, $data["password"], $data["password_re"])) {
                $this->message->success("Senha alterada com sucesso. Vamos controlar?")->flash();
                $json["redirect"] = url("/entrar");
            } else {
                $json["message"] = $auth->message()->before("Ooops! ")->render();
            }

            echo json_encode($json);
            return;
        }

        $head = $this->seo->render(
            "Crie sua nova senha no " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/recuperar"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("auth-reset", [
            "head" => $head,
            "code" => $data["code"]
        ]);
    }

    /**
     * SITE REGISTER
     * @param null|array $data
     */
    public function register(?array $data): void
    {
        //var_dump($data);
        if(Auth::user()){
            redirect("/app");
        }

        if(!empty($data['csrf'])){
            if(!csrf_verify($data)){
                $json['message'] = $this->message->error("Erro ao Enviar, Favor use o formulário...")->render();
                echo json_encode($json);
                return;
            }
            if(in_array("", $data)){
                $json['message'] = $this->message->info("Preencha corretamente todos os campos, Obrigado...")->render();
                echo json_encode($json);
                return;
            }


            $pass = $data["password"];
            $auth = new Auth();

            $user = new User();
            $user->name = $data['first_name'];
            $user->sobrenome = $data['last_name'];
            $user->cpf = preg_replace("/[^0-9]/", "", $data["cpf"]);
            $user->email = $data['email'];
            $user->password = $data['password'];
            $user->pass = $pass;
            $user->level = '1';


            $user->dtfim = date('Y/m/d', strtotime('-1 days'));
            $user->status = '2';
            $user->margem = 0;



            if(!validaCPF($user->cpf)){
                $json["message"] = $this->message->warning("O CPF informado não é inválido \"{$user->cpf}\"!")->render();
                echo json_encode($json);
                return;
            }

            $compareCpfCadastrado = (new User())->find("cpf = :e","e={$user->cpf}")->fetch();

            if (!empty($compareCpfCadastrado)) {
                $json["message"] = $this->message->warning("O CPF informado {$user->cpf} já está cadastrado, Solicite troca de senha ou fale conosco!")->render();
                echo json_encode($json);
                return;
            }

//            var_dump($data, $user);
//            exit();


            if ($auth->register($user)) {
                $json['redirect'] = url("/confirma");
            } else {
                $json['message'] = $auth->message()->before("Ooops!!! ")->render();
            }

            echo json_encode($json);
            return;
        }
        $head = $this->seo->render(
            "Criar Conta - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/cadastrar"),
            theme("/assets/images/share.jpg"),
            true
        );

        echo $this->view->render("auth-register", [
            "head" => $head
        ]);
    }

    /**
     * SITE OPT-IN CONFIRM
     */
    public function confirm(): void
    {
        $head = $this->seo->render(
            "Confirme Seu Cadastro - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/confirma"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("optin", [
            "head" => $head,
            "data" => (object)[
                "title" => "Falta pouco! Confirme seu cadastro.",
                "desc" => "Enviamos um link de confirmação para seu e-mail. Acesse e siga as instruções para validar o seu cadastro
                e comece a utilizar o Sistema FEKTO!",
//                "image" => theme("/assets/images/optin-confirm.jpg"),
                "image" => theme("/assets/images/logosgeral/logo_original.png"),
                "link" => url("/entrar"),
                "linkTitle" => "Clique aqui e para Logar!"
            ]
        ]);
    }

    /**
     * SITE OPT-IN SUCCESS
     * @param array $data
     */
    public function success(array $data): void
    {
        $email = base64_decode($data['email']);
        $user = (new User())->findByEmail($email);
        if($user && $user->status != "confirmed"){
            $user->confirm = "confirmed";
            $user->save();
        }
        $head = $this->seo->render(
            "Bemvindo(a) ao " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/obrigado"),
            theme("/assets/images/share.jpg"),
            ""
        );
        echo $this->view->render("optin", [
            "head" => $head,
            "data" => (object)[
                "title" => "<b style='color: red;'>Tudo pronto. Você já pode usar o Sistema FEKTO :)</b>",
                "desc" => "Bem-vindo(a), todos os recursos estão liberados",
                "image" => theme("/assets/images/optin-success.jpg"),
                "link" => url("/entrar"),
                "linkTitle" => "Fazer Login"
            ]
        ]);
    }

    /**
     * SITE TERMS
     */
    public function terms(): void
    {
        $head = $this->seo->render(
            CONF_SITE_NAME . " - Termos de uso",
            CONF_SITE_DESC,
            url("/termos"),
            theme("/assets/images/share.jpg")
        );

        echo $this->view->render("terms", [
            "head" => $head
        ]);
    }

    /**
     * SITE NAV ERROR
     * @param array $data
     */
    public function error(array $data): void
    {
        $error = new \stdClass();

        switch ($data['errcode']) {
            case "problemas":
                $error->code = "OPS";
                $error->title = "Estamos enfrentando problemas!";
                $error->message = "Parece que nosso serviço não está diponível no momento. Já estamos vendo isso mas caso precise, envie um e-mail :)";
                $error->linkTitle = "ENVIAR E-MAIL";
                $error->link = "mailto:" . CONF_MAIL_SUPORT;
                break;

            case "manutencao":
                $error->code = "OPS";
                $error->title = "Desculpe. Estamos em manutenção!";
                $error->message = "Voltamos logo! Por hora estamos trabalhando para melhorar nosso conteúdo para você controlar melhor as suas contas :P";
                $error->linkTitle = null;
                $error->link = null;
                break;

            default:
                $error->code = $data['errcode'];
                $error->title = "Ooops. Conteúdo indispinível :/";
                $error->message = "Sentimos muito, mas o conteúdo que você tentou acessar não existe, está indisponível no momento ou foi removido :/";
                $error->linkTitle = "Continue navegando!";
                $error->link = url_back();
                break;
        }

        $head = $this->seo->render(
            "{$error->code} | {$error->title}",
            $error->message,
            url("/ops/{$error->code}"),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("error", [
            "head" => $head,
            "error" => $error
        ]);
    }
}