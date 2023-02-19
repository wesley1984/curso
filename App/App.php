<?php

namespace Source\App;

use Source\Models\Filiados\Galery;
use Source\Models\Filiados\Transferencia;
use Source\Models\Finan\Ativar_Aluno;
use Source\Models\Finan\Cat_Pagamentos;
use Source\Models\Finan\Empresa;
use function Composer\Autoload\includeFile;
use Mpdf\Mpdf;
use Source\Core\Connect;
use Source\Core\Controller;
use Source\Core\Session;
use Source\Core\View;
use Source\Models\Auth;
use Source\Models\CafeApp\AppCategory;
use Source\Models\CafeApp\AppCreditCard;
use Source\Models\CafeApp\AppInvoice;
use Source\Models\CafeApp\AppOrder;
use Source\Models\CafeApp\AppPlan;
use Source\Models\CafeApp\AppSubscription;
use Source\Models\Filiados\Academias;
use Source\Models\Filiados\Categorias;
use Source\Models\Filiados\Certificados;
use Source\Models\Filiados\Faixa;
use Source\Models\Filiados\Karatecas;
use Source\Models\Filiados\Ranking;
use Source\Models\Finan\Financeiro;
use Source\Models\Post;
use Source\Models\Report\Access;
use Source\Models\Report\Online;
use Source\Models\User;
use Source\Support\Email;
use Source\Support\Message;
use Source\Support\Pager;
use Source\Support\Thumb;
use Source\Support\Upload;
use Source\Models\CafeApp\AppWallet;
use function React\Promise\all;

/*
 * Class App
 * @package Source\App
 */
class App extends Controller
{
    /** @var User */
    private $user;

    private $whats;

    /**
     * App constructor.
     */
    public function __construct()
    {
        parent::__construct(__DIR__ . "/../../themes/" . CONF_VIEW_APP . "/");
        /*
                Antes: verifica se tem um usuário com sessão ativa e logado corretamente e são tiver manda msg e redireciona para login /entrar
                if(!Auth::user()){
                    $this->message->warning("Efetue login para acessar o painel de controle...")->flash();
                    redirect("/entrar");
                }
                 * */
        //AGORA: ciramos o private $user; abaixo a cima do construtor, e armazenamos a sessão do usuário direto no if
        //se não tiver usuário logado corretamente redireciona para o login /entrar, caso contrário já alimenta
        //a variavel private $user; com os dados do usuário logado e estes ficão disponível por todoOpainel

        if (!$this->user = Auth::user()) {
            $this->message->warning("Efetue login para acessar o APP.")->flash();
            redirect("/entrar");
        }

//        if (1 == 1) {
//            (new Message())->info("Desculpe, " . Auth::user()->fullName() . ". Mas o Sistema esta temporariamente indisponível... ")->flash();
//
//            Auth::logout();
//            redirect("/entrar");
//        }


        (new Access())->report();
        (new Online())->report();
        (new AppWallet())->start($this->user);
        (new AppInvoice())->fixed($this->user, 3);

//        var_dump($this->user);

        if ($this->user->confirm != "confirmed") {
            $session = new Session();
            if (!$session->has("appconfirmed")) {
                $this->message->info("IMPORTANTE: Acesse seu e-mail para confirmar seu cadastro e ativar todos os recursos.")->flash();
                $session->set("appconfirmed", true);
                (new Auth())->register($this->user);
            }
        }
        if (!$this->user->contrato == "confirmed") {
            if(!empty($_GET["contrato"])){

                $contrato = (new User())->find("id = :id","id={$this->user->id}")->fetch();
                if($contrato){
                    $dtcontrato =  date("Y-m-d H:i:s");
                    $sqlUpdate = "UPDATE usuario SET contrato = 'confirmed', dtContrato = '$dtcontrato'  WHERE id = '$contrato->id'";
                    $sqlUpdate = Connect::getInstance()->prepare($sqlUpdate);
                    $sqlUpdate->execute();
                }
                $this->message->success("Contrato aceito e assinado digitalmente, Obrigado por fazer parte dessa familia. Oss.")->flash();
                redirect("/app/estatuto");
            }else {
                $this->message->warning("Para continuar utilizando o Sistema FEKTO você precisa aceitar os termos de nosso Estatuto. Oss.")->flash();
                redirect("/app/estatuto");
            }
        }

//        $card = (new AppCreditCard())->findById(1);
//        $tr = $card->transaction(5000);
//        var_dump($tr->callback());
        $this->whats = (new Empresa())->find()->fetch();

//        $whatsapp = (new Empresa())->find()->fetch();
//        var_dump($whatsapp->celular1, $whatsapp->whatsapp());
    }

    public function dash(?array $data): void
    {
        if(!empty($data["wallet"])){
            $session = new Session();

            if($data["wallet"] == "all"){
                $session->unset("walletfilter");
                echo json_encode(["filter" => true]);
                return;
            }

            $wallet = filter_var($data["wallet"], FILTER_VALIDATE_INT);
            $getWallet = (new AppWallet())->find("user_id = :user AND id = :id",
                "user={$this->user->id}&id={$wallet}")->count();

            if($getWallet){
                $session->set("walletfilter", $wallet);
            }

            echo json_encode(["filter" => true]);
            return;
        }



        //CHART UPDATE
        $chartData = (new AppInvoice())->chartData($this->user);
        $categories = str_replace("'", "", explode(",", $chartData->categories));
        $json["chart"] = [
            "categories" => $categories,
            "income" => array_map("abs", explode(",", $chartData->income)),
            "expense" => array_map("abs", explode(",", $chartData->expense))
        ];

        //WALLET
        $wallet = (new AppInvoice())->balance($this->user);
        $wallet->wallet = str_price($wallet->wallet);
        $wallet->status = ($wallet->balance == "positive" ? "gradient-green" : "gradient-red");
        $wallet->income = str_price($wallet->income);
        $wallet->expense = str_price($wallet->expense);
        $json["wallet"] = $wallet;

        echo json_encode($json);
    }

    /**
     * APP HOME
     */
    public function home()
    {

//        $karatecas = (new Karatecas())->find()->fetch(true);
//        $academias = (new Academias())->find()->fetch(true);
//        $certifica = (new Certificados())->find()->fetch(true);

        $campeonato = (new Post())->findJoin("category = '2' AND camStatus = '1' AND dtevento >= NOW() AND del = '0'",
            "",
            "posts.*,
                    cidades.cidNome,
                    (SELECT COUNT(ranking.cam_id) FROM ranking WHERE ranking.cam_id = posts.id) AS alunos_inscritos",
            "LEFT JOIN cidades ON cidades.cidId = posts.cidade
        ");
        $campeonatoProximos = (new Post())->find("category = '2' AND camStatus = '1' AND dtevento >= NOW() AND del = '0'");

        $academias = (new Academias())->find("user_id = :id","id={$this->user->id}", "
         academia.id, academia.user_id, academia.acaNome, academia.acaTipo, academia.photo,
         (SELECT COUNT(karateca.aca_id) FROM karateca WHERE karateca.aca_id = academia.id AND status = '1' AND dtfim >= NOW()) AS alunos_ativos, 
         (SELECT COUNT(karateca.aca_id) FROM karateca WHERE karateca.aca_id = academia.id AND (status = '2' OR dtfim <= NOW())) AS alunos_inativos, 
         (SELECT COUNT(karateca.aca_id) FROM karateca WHERE karateca.aca_id = academia.id) AS total_alunos", "");


        $inforHome = (new Academias())->find("user_id = :id","id={$this->user->id}", "
         academia.id, academia.user_id,
         (SELECT COUNT(academia.user_id) FROM academia WHERE academia.user_id = :id) AS academias_prof, 
         (SELECT COUNT(karateca.aca_id) FROM karateca WHERE karateca.aca_id = academia.id AND status = '1' AND dtfim >= NOW()) AS alunos_ativos, 
         (SELECT COUNT(karateca.aca_id) FROM karateca WHERE karateca.aca_id = academia.id AND (status = '2' OR dtfim <= NOW())) AS alunos_inativos, 
         (SELECT COUNT(karateca.aca_id) FROM karateca WHERE karateca.aca_id = academia.id) AS total_alunos")->fetch();

        if(empty($inforHome)){
            $inforHome = null;
        }

        $inforHomePresi = (new Post())->find("category = :category","category=1", "
         posts.category,
         (SELECT COUNT(usuario.id) FROM usuario WHERE usuario.level = 1) AS professores, 
         (SELECT COUNT(usuario.id) FROM usuario WHERE usuario.level = 1 AND status = '1' AND dtfim >= NOW()) AS professores_ativos, 
         (SELECT COUNT(usuario.id) FROM usuario WHERE usuario.level = 1 AND (status = '2' OR dtfim <= NOW())) AS professores_Inativos, 
         (SELECT COUNT(karateca.aca_id) FROM karateca) AS total_karatecas, 
         (SELECT COUNT(karateca.aca_id) FROM karateca WHERE status = '1' AND dtfim >= NOW()) AS alunos_ativos, 
         (SELECT COUNT(karateca.aca_id) FROM karateca WHERE (status = '2' OR dtfim <= NOW())) AS alunos_inativos, 
         (SELECT COUNT(certificado.id) FROM certificado WHERE certStatus = '1') AS diplomas_emitidos, 
         (SELECT COUNT(academia.id) FROM academia) AS total_academias, 
         (SELECT COUNT(posts.id) FROM posts WHERE category = '1' AND status = 'post') AS total_noticias,
         (SELECT COUNT(posts.id) FROM posts WHERE category = '2' AND status = 'camp' AND camStatus = '1') AS total_camp
         ")->fetch();

        if(empty($inforHomePresi)){
            $inforHomePresi = null;
        }

        $head = $this->seo->render(
            "Olá {$this->user->name}. Vamos controlar? - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg"),
            false
        );

        //CHART
        $chartData = (new AppInvoice())->chartData($this->user);
        //END CHART

        //INCOME E EXPENSE

        $whereWallet = "";
        if((new Session())->has("walletfilter")){
            $whereWallet = " AND wallet_id = " . (new Session())->walletfilter;
        }
        $income = (new AppInvoice())
            ->find("user_id = :user AND type = 'income' AND status = 'unpaid' AND date(due_at) <= date(NOW() + INTERVAL 1 MONTH) {$whereWallet}",
                "user={$this->user->id}")
            ->order("due_at")
            ->fetch(true);

        $expense = (new AppInvoice())
            ->find("user_id = :user AND type = 'expense' AND status = 'unpaid' AND date(due_at) <= date(NOW() + INTERVAL 2 MONTH) {$whereWallet}",
                "user={$this->user->id}")
            ->order("due_at")
            ->fetch(true);
        //END INCOME E EXPENS

        //CARTEIRA WALLET
        $wallet = (new AppInvoice())->balance($this->user);
        //END CARTEIRA WALLET

        //POSTS
        $posts = (new Post())->findPost()->limit(3)->order("post_at DESC")->fetch(true);
        //END POSTS

        $get_valor_ativar = (new Faixa())->find("faixaId = '100'")->fetch();

        echo $this->view->render("home", [
            "head" => $head,
            "chart" => $chartData,
            "income" => $income,
            "expense" => $expense,
            "wallet" => $wallet,
            "posts" => $posts,
            "user" => $this->user,
            "whats" => $this->whats,
            "inforHomePresi" => $inforHomePresi,
            "inforHome" => $inforHome,
            "valor_anuidade" => $get_valor_ativar,
            "academiasAtiva" => $academias->fetch(true),
            "campeonatoAtivo" => $campeonato->order("dtevento ASC")->fetch(),
            "campeonatoProximos" => $campeonatoProximos->order("dtevento ASC")->limit(2)->offset(1)->fetch(true)
        ]);
    }





    /**
     * @param array $data
     * @throws \Exception
     */
    public function filter(array $data)
    {
        $status = (!empty($data['status']) ? $data['status'] : "all");
        $category = (!empty($data['category']) ? $data['category'] : "all");
        $date = (!empty($data['date']) ? $data['date'] : date("m/Y"));

        list($m, $y) = explode("/", $date);
        $m = ($m >= 1 && $m <= 12 ? $m : date("m"));
        $y = ($y <= date("Y", strtotime("+10year")) ? $y : date("Y", strtotime("+10year")));

        $start = new \DateTime(date("Y-m-t"));
        $end = new \DateTime(date("Y-m-t",strtotime("{$y}-{$m}+1month")));
        $diff = $start->diff($end);

        if(!$diff->invert){
            $afterMonths = (floor($diff->days / 30));
            (new AppInvoice())->fixed($this->user, $afterMonths);
        }


        $redirect = ($data["filter"] == "income" ? "receber" : "pagar");
        $json["redirect"] = url("/app/{$redirect}/{$status}/{$category}/{$m}-{$y}");
        echo json_encode($json);
    }

    /**
     * @param array|null $data
     */
    public function income(?array $data): void
    {
        $head = $this->seo->render(
            "Minhas receitas - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg"),
            false
        );

        $categories = (new AppCategory())
            ->find("type = :t", "t=income", "id, name")
            ->order("order_by, name")
            ->fetch(true);

        echo $this->view->render("invoices", [
            "user" => $this->user,
            "head" => $head,
            "type" => "income",
            "categories" => $categories,
            "invoices" => (new AppInvoice())->filter($this->user, "income", ($data ?? null)),
            "filter" => (object)[
                "status" => ($data["status"] ?? null),
                "category" => ($data["category"] ?? null),
                "date" => (!empty($data["date"]) ? str_replace("-", "/", $data['date']) : null)
            ]
        ]);
    }


    /**
     * @param array|null $data
     */
    public function expense(?array $data): void
    {
        $head = $this->seo->render(
            "Minhas despesas - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg"),
            false
        );

        $categories = (new AppCategory())
            ->find("type = :t", "t=expense", "id, name")
            ->order("order_by, name")
            ->fetch(true);

        echo $this->view->render("invoices", [
            "user" => $this->user,
            "head" => $head,
            "type" => "expense",
            "categories" => $categories,
            "invoices" => (new AppInvoice())->filter($this->user, "expense", ($data ?? null)),
            "filter" => (object)[
                "status" => ($data['status'] ?? null),
                "category" => ($data['category'] ?? null),
                "date" => (!empty($data['date']) ? str_replace("-", "/", $data['date']) : null)
            ]
        ]);
    }


    /**
     *
     */
    public function fixed(): void
    {
        $head = $this->seo->render(
            "Minhas contas fixas - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg"),
            false
        );

        $whereWallet = "";
        if((new Session())->has("walletfilter")){
            $whereWallet = " AND wallet_id = " . (new Session())->walletfilter;
        }

        echo $this->view->render("recurrences", [
            "head" => $head,
            "invoices" => (new AppInvoice())->find("user_id = :user AND type IN('fixed_income', 'fixed_expense') {$whereWallet}",
                "user={$this->user->id}")->fetch(true)
        ]);
    }

    public function wallets(?array $data)
    {
        //CREAYE CARTEIRA WALLET
        if(!empty($data["wallet"]) && !empty($data["wallet_name"])){

            /*RESTINGIR ACESSO A USUARIO PRO*/
            //PREMIUM RESOURCE
//            $subscribe = (new AppSubscription())->find("user_id = :user AND status != :status",
//                "user={$this->user->id}&status=canceled");
//
//            if (!$subscribe->count()) {
//                $this->message->error("Desculpe {$this->user->fullName()}, para criar novas carteiras é preciso ser PRO. Confira abaixo...")->flash();
//                echo json_encode(["redirect" => url("/app/assinatura")]);
//                return;
//            }

            $wallet = new AppWallet();
            $wallet->user_id = $this->user->id;
            $wallet->wallet = filter_var($data["wallet_name"], FILTER_SANITIZE_STRIPPED);
            $wallet->free = 1;
            $wallet->save();

            echo json_encode(["reload" => true]);
            return;
        }

        //EDIT CARTEIRA WALLET
        if(!empty($data["wallet"]) && !empty($data["wallet_edit"])){
            $wallet = (new AppWallet())->find("user_id = :user AND id = :id",
                "user={$this->user->id}&id={$data["wallet"]}")->fetch();
            if($wallet){
                $wallet->wallet = filter_var($data["wallet_edit"], FILTER_SANITIZE_STRIPPED);
                $wallet->save();
            }

            echo json_encode(["wallet_edit" => true]);
            return;
        }

        //DELETE CARTEIRA WALLET
        if(!empty($data["wallet"]) && !empty($data["wallet_remove"])){
            $wallet = (new AppWallet())->find("user_id = :user AND id = :id",
                "user={$this->user->id}&id={$data["wallet"]}")->fetch();

            if($wallet){
                $wallet->destroy();
                (new Session())->unset("walletfilter");
            }
            echo json_encode(["wallet_remove" => true]);
            return;
        }

        $head = $this->seo->render(
            "Minhas Carteiras - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg"),
            false
        );

        $wallets = (new AppWallet())->find("user_id = :user", "user={$this->user->id}")->fetch(true);

        echo $this->view->render("wallets", [
            "head" => $head,
            "wallets" => $wallets
        ]);
    }


    /**
     * @param array $data
     */
    public function launch(array $data): void
    {
        if(request_limit("applaunch", 30, 60 * 5)){
            $json['message'] = $this->message->warning("Foi muito rápido {$this->user->name}! Por favor aguara 5 minutos para novos lancementos...")->render();
            echo json_encode($json);
            return;
        }

        $wallet = (new AppWallet())->find("user_id = :user AND id = :id",
            "user={$this->user->id}&id={$data["wallet"]}")->fetch();

        if (!$wallet) {
            $json["message"] = $this->message->warning("Ooops, seu periodo de utilização do gerenciador fianciero vencel, para continuar adiquira outro.")->render();
            echo json_encode($json);
            return;
        }
        /*RESTINGIR ACESSO A USUARIO PRO*/
        //PREMIUM RESOURCE
//        $subscribe = (new AppSubscription())->find("user_id = :user AND status != :status",
//            "user={$this->user->id}&status=canceled");
//
//        if (!$subscribe->count()) {
//            $this->message->error("Sua carteira {$wallet->wallet} é de usuario PRO {$this->user->name}. Para controla-la é preciso ser Usuario PRO. Assine abaixo...")->flash();
//            echo json_encode(["redirect" => url("/app/assinatura")]);
//            return;
//        }

        if(!empty($data['enrollments']) && ($data['enrollments'] < 2 || $data['enrollments'] > 420)){
            $json['message'] = $this->message->warning("Ooopss {$this->user->name}! para lançar, o numero de parcela deve ser entre 2 e 420, OK")->render();
            echo json_encode($json);
            return;
        }

        $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
        $status =(date($data["due_at"]) <= date("Y-m-d") ? "paid" : "unpaid");

        $invoice = (new AppInvoice());
        $invoice->user_id = $this->user->id;
        $invoice->wallet_id = $data['wallet'];
        $invoice->category_id = $data['category'];
        $invoice->invoice_of = null;
        $invoice->description = $data['description'];
        $invoice->type = ($data['repeat_when'] == "fixed" ? "fixed_{$data['type']}" : $data['type']);
        $invoice->value = str_replace([".", ","], ["", "."], $data["value"]);
        $invoice->currency = $data['currency'];
        $invoice->due_at = $data['due_at'];
        $invoice->repeat_when = $data['repeat_when'];
        $invoice->period = (!empty($data["period"]) ? $data["period"] : "month");
        $invoice->enrollments = (!empty($data["enrollments"]) ? $data["enrollments"] : 1);
        $invoice->enrollment_of = 1;
        $invoice->status = ($data['repeat_when'] == "fixed" ? "paid" : $status);

        if(!$invoice->save()){
            $json['message'] = $invoice->message()->render();
            echo json_encode($json);
            return;
        }

        if($invoice->repeat_when == "enrollment"){
            $invoiceOf = $invoice->id;
            for($enrollment = 1; $enrollment < $invoice->enrollments; $enrollment++){
                $invoice->id = null;
                $invoice->invoice_of = $invoiceOf;
                $invoice->due_at = date("Y-m-d", strtotime($data['due_at'] . "+{$enrollment}month"));
                $invoice->status = (date($invoice->due_at) <= date("Y-m-d") ? "paid" : "unpaid");
                $invoice->enrollment_of = $enrollment + 1;
                $invoice->save();
            }
        }
        if($invoice->type == "income"){
            $this->message->success("Receita lançada com sucesso, use o filtro para controlar...")->render();
        }else{
            $this->message->success("Respesa lançada com sucesso, use o filtro para controlar...")->render();
        }

        $json["reload"] = true;
        echo json_encode($json);
    }


    /**
     * @param array $data
     */
    public function support(array $data): void
    {
        if(empty($data['message'])){
            $json['message'] = $this->message->warning("Para enviar escreva sua mensagem...")->render();
            echo json_encode($json);
            return;
        }

        if(request_limit("appsuport", 3, 60 * 5)){
            $json['message'] = $this->message->warning("Por favor, aguarde 5 minutos para enviar nova mensagem. O mais rápido possível responderemos as mensagens anteriores")->render();
            echo json_encode($json);
            return;
        }

        if(request_repeat("message", $data['message'])){
            $json['message'] = $this->message->info("Já recebemos sua solicitação {$this->user->name}. Agradecemos pelo contato e responderemos em breve.")->render();
            echo json_encode($json);
            return;
        }

        $subject = date_fmt() . " - {$data['subject']}";
        $message = filter_var($data['message'], FILTER_SANITIZE_STRING);

        $view = new View(__DIR__ . "/../../shared/views/email");
        $body = $view->render("mail", [
            "subject" => $subject,
            "message" => str_textarea($message)
        ]);

        (new Email())->bootstrap(
            $subject,
            $body,
            CONF_MAIL_SUPORT,
            "Suporte ". CONF_SITE_NAME
        )->send($this->user->email, $this->user->name . $this->user->last_name);
//        Aqui en vez de enviar o email na frente do usuário envia paara o queue e nas taferas que cron envia por baixo dos panos...
//        )->queue($this->user->email, $this->user->name . $this->user->last_name);

        $this->message->success("Recebemos sua solicitação {$this->user->name}. Agradecemos pelo contato e responderemos em breve.")->flash();
        $json['reload'] = true;
        echo json_encode($json);
    }


    /**
     * @param array $data
     */
    public function onpaid(array $data): void
    {
        $invoice = (new AppInvoice())
            ->find("user_id = :user AND id = :id", "user={$this->user->id}&id={$data['invoice']}")
            ->fetch();

        if(!$invoice){
            $this->message->error("Oooops! Ocorreu um erro ao atualizar o lançamento :/")->flash();
            $json["reload"] = true;
            echo json_encode($json);
            return;
        }

        $invoice->status = ($invoice->status == "paid" ? "unpaid" : "paid");
        $invoice->save();

        $y = date("Y");
        $m = date("m");
        if(!empty($data['date'])){
            list($m, $y) = explode("/", $data["date"]);
        }
        $json["onpaid"] = (new AppInvoice())->balanceMonth($this->user, $y, $m, $invoice->type);
        echo json_encode($json);
    }


    /**
     * @param array $data
     */
    public function invoice(array $data)
    {
        if(!empty($data["update"])){
            $invoice = (new AppInvoice())->find("user_id = :user AND id = :id",
                "user={$this->user->id}&id={$data["invoice"]}")->fetch();

            if(!$invoice){
                $json["message"] = $this->message->error("Ooops! {$this->user->name}, não foi possível carregar a fatura, tente novamente.")->render();
                echo json_encode($json);
                return;
            }

            if($data["due_day"] < 1 || $data["due_day"] > $dayOfMonth = date("t", strtotime($invoice->due_at))){
                $json["message"] = $this->message->warning("O vencimento deve ser entre o dia 01 e o dia {$dayOfMonth} para este mês.")->render();
                echo json_encode($json);
                return;
            }
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
            $due_day = date("Y-m", strtotime($invoice->due_at)) . "-" . $data["due_day"];
            $invoice->category_id = $data["category"];
            $invoice->description = $data["description"];
            $invoice->due_at = date("Y-m-d", strtotime($due_day));
            $invoice->value = str_replace([".", ","], ["", "."], $data["value"]);
            $invoice->wallet_id = $data["wallet"];
            $invoice->status = $data["status"];

            if(!$invoice->save()){
                $json["message"] = $invoice->message()->before("Oooops! ")->render();
                echo json_encode($json);
                return;
            }

            $invoiceOf = (new AppInvoice())->find("user_id = :user AND invoice_of = :of",
                "user={$this->user->id}&of={$invoice->id}")->fetch(true);

            if(!empty($invoiceOf) && in_array($invoice->type, ["fixed_income", "fixed_expense"])){
                foreach ($invoiceOf as $invoiceItem){
                    if($data["status"] == "unpaid" && $invoiceItem->status == "unpaid"){
                        $invoiceItem->destroy();
                    }else{
                        $due_day = date("Y-m", strtotime($invoiceItem->due_at)) . "-" . $data["due_day"];
                        $invoiceItem->category_id = $data["category"];
                        $invoiceItem->description = $data["description"];
                        $invoiceItem->wallet_id = $data["wallet"];

                        if($invoiceItem->status == "unpaid"){
                            $invoiceItem->value = str_replace([".", ","], ["", "."], $data["value"]);
                            $invoiceItem->due_at = date("Y-m-d", strtotime($due_day));
                        }

                        $invoiceItem->save();
                    }
                }
            }

            $json["message"] = $this->message->success("Pronto {$this->user->name}, A atualização foi feita com sucesspo.")->render();
            echo json_encode($json);
            return;
        }

        $head = $this->seo->render(
            "Aluguel - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg"),
            false
        );

        $invoice = (new AppInvoice())->find("user_id = :user AND id = :invoice",
            "user={$this->user->id}&invoice={$data["invoice"]}")->fetch();

        if(!$invoice){
            $this->message->error("Ooooops Você tentou acessar uma fatura que não existe.")->flash();
            redirect("/app");
        }

        echo $this->view->render("invoice", [
            "head" => $head,
            "invoice" => $invoice,
            "wallets" => (new AppWallet())
                ->find("user_id = :user", "user={$this->user->id}", "id, wallet")
                ->order("wallet")
                ->fetch(true),
            "categories" => (new AppCategory())
                ->find("type = :type", "type={$invoice->category()->type}")
                ->order("order_by")
                ->fetch(true)
        ]);
    }

    /**
     * @param array $data
     */
    public function remove(array $data): void
    {
        $invoice = (new AppInvoice())->find("user_id = :user AND id = :invoice",
            "user={$this->user->id}&invoice={$data["invoice"]}")->fetch();

//        var_dump($invoice);
//        exit;
        if($invoice){
            $invoice->destroy();
        }

        $this->message->success("Tudo pronto {$this->user->name}, o lançamento foi removido com sucesso!")->flash();
        if($invoice->type == "income"){
            $json["redirect"] = url("/app/receber");
            echo json_encode($json);
        }
        $json["redirect"] = url("/app/pagar");
        echo json_encode($json);
    }


    /**
     * @param array|null $data
     * @throws \Exception
     */
    public function profile(?array $data):void
    {
        if(!empty($data["update"])){
            list($d, $m, $y) = explode("/", $data["nasc"]);
            $user = (new User())->findById($this->user->id);
            $user->name = $data["name"];
            $user->sobrenome = $data["sobrenome"];
            $user->sexo = $data["sexo"];
            $user->nasc = "{$y}-{$m}-{$d}";
            $user->cpf = preg_replace("/[^0-9]/", "", $data["cpf"]);
            $user->cidade = $data["cidade"];
            $user->margem = preg_replace("/[^0-9]/", "", $data["margem"]);
            $user->celu = $data["celu"];

            if(!validaCPF($user->cpf)){
                $json["message"] = $this->message->error("O CPF informado não é inválido \"{$user->cpf}\"!")->render();
                echo json_encode($json);
                return;
            }

//            $compareCpfCadastrado = (new User())->find("cpf = :e","e={$userUpdate->cpf}")->fetch();
            $compareCpfCadastrado = (new User())->find("cpf = :e AND id != :i","e={$user->cpf}&i={$this->user->id}")->fetch();

            if (!empty($compareCpfCadastrado)) {
                $json["message"] = $this->message->error("O CPF informado {$user->cpf} já está cadastrado, Solicite troca de senha ou fale conosco!")->render();
                echo json_encode($json);
                return;
            }

            if(!empty($_FILES["photo"])){
                if($user->photo && file_exists(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$user->photo}")){
                    unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$user->photo}");
                    (new Thumb())->flush($user->photo);
                }
                $files = $_FILES["photo"];
                $upload = new Upload();
                $image = $upload->image($files, $user->name, 1200);

                if(!$image){
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }
                $user->photo = $image;
            }

            if(!empty($data["password"])) {
                if (empty($data["password_re"]) || $data["password"] != $data["password_re"]) {
                    $json["message"] = $this->message->warning("Para alterar a sua senha, informe e repita a nova senha!")->render();
                    echo json_encode($json);
                    return;
                }
                $user->pass = $data["password"];
                $user->password = $data["password"];
            }

            //$user->confirm = ($user->confim == "confirmed" ? "confirmed" : "registred");
//            var_dump($user);
//            exit();

            if(!$user->save()){
                $json["message"] = $user->message()->render();
                echo json_encode($json);
                return;
            }

            $json["message"] = $this->message->success("Pronto {$user->name}, seus dados forma atualizados com sucesso!")->render2();
            echo json_encode($json);
            return;
        }
        $head = $this->seo->render(
            "Meu perfil - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/app/perfil"),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("profile", [
            "head" => $head,
            "user" => $this->user,
            //"photo" => ($this->user->photo() ? image($this->user->photo, 360, 360) : theme("/assets/images/avatar.jpg", CONF_VIEW_THEME)),
            "getCidades" => $this->getAllCidades(),
            "faixa" => $this->getAllFaixa()
        ]);
    }



    /*****************************************************************/


    public function academ(?array $data):void
    {
        if(!empty($data['aca_id'])){
            $academiaDelete = (new Academias())->find("id = :id","id={$data['aca_id']}",
                "id, user_id, photo,
                (SELECT COUNT(karateca.aca_id) FROM karateca WHERE karateca.aca_id = :id) AS totalalunos
                ")->fetch();

            if($academiaDelete->totalalunos > 0){
                $this->message->warning("Oops, Você não você não pode remover uma academia que ainda tem alunos, remova todos os alunos para poder remover esta academia.")->flash();
                echo json_encode(["redirect" => url("/app/academias")]);
                return;
            }


            if($this->user->id != $academiaDelete->user_id){
                $this->message->warning("Oops, Você não tem permissão para deletar esta academa ou Ela ão pertense a sua base de dados...")->flash();
                echo json_encode(["redirect" => url("/app/academias")]);
                return;
            }

            if($academiaDelete->totalalunos >= 1){
                $this->message->warning("Oops, Professor esta Academia tem alunos cadastrados, assim a mesma não pode ser excluida...")->flash();
                echo json_encode(["redirect" => url("/app/academias")]);
                return;
            }
            if($academiaDelete->photo && file_exists(__DIR__ . "/../../storage/{$academiaDelete->photo}")){
                unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$academiaDelete->photo}");
                (new Thumb())->flush($academiaDelete->photo);

            }
            $academiaDelete->destroy();
            $this->message->success("Ok academia removido com sucesso")->flash();
            echo json_encode(["redirect" => url("/app/academias")]);
            return;
        }



        $academias = (new Academias())->findJoin("user_id = :id","id={$this->user->id}", "
         academia.id, academia.user_id, academia.acaNome, academia.acaEnd, academia.acaCidade, academia.acaTipo, 
         academia.acaStatus, academia.acaCadastro, cidades.cidNome AS cidade_nome, 
         (SELECT COUNT(karateca.aca_id) FROM karateca WHERE karateca.aca_id = academia.id) AS total_alunos", "
         LEFT JOIN cidades
         ON cidades.cidId = academia.acaCidade");

        $head = $this->seo->render(
            "Minas Academias - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/app/academias"),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("profeAcademias", [
            "head" => $head,
            "user" => $this->user,
            "academias" => $academias->fetch(true),
            "cidades" => $this->getAllCidades()
        ]);
    }

    public function academiaAdd(?array $data):void
    {

        if(!empty($data["createAcademia"])){
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
            $acadCreate = (new Academias());
            $acadCreate->user_id = $data["user_id"];
            $acadCreate->acaNome = $data["acaNome"];
            $acadCreate->acaTipo = $data["acaTipo"];
            $acadCreate->acaTel = preg_replace("/[^0-9]/", "", $data["acaTel"]);
            $acadCreate->acaEnd = $data["acaEnd"];
            $acadCreate->acaCidade = $data["acaCidade"];
            $acadCreate->acaStatus = '1';
            $acadCreate->acaCadastro = date("Y-m-d H:i:s");

            if(!empty($_FILES["imagem"])){
                $files = $_FILES["imagem"];
                $upload = new Upload();
                $image = $upload->image($files, $acadCreate->user_id."-".$acadCreate->acaName);

                if(!$image){
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }
                $acadCreate->photo = $image;
            }
            if(!$acadCreate->save()){
                $json["message"] = $acadCreate->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Academia cadastrada com Sucesso...")->flash();
            echo json_encode(["redirect" => url("/app/academias")]);
            return;
        }

        if(!empty($data["updateAcademia"])){
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
            $acadUpdate = (new Academias())->findById($data["aca_id"]);

            if(Auth::user()->id != $acadUpdate->user_id && Auth::user()->level < 5){
                $this->message->error("Você não tem permissão para editar esta Academia...")->flash();
                echo json_encode(["redirect" => url("/app/academias")]);
                return;
            }

            $acadUpdate->acaNome = $data["acaNome"];
            $acadUpdate->acaTel = preg_replace("/[^0-9]/", "", $data["acaTel"]);
            $acadUpdate->acaEnd = $data["acaEnd"];
            $acadUpdate->acaCidade = $data["acaCidade"];

            if(!empty($_FILES["imagem"])){
                if($acadUpdate->photo && file_exists(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$acadUpdate->photo}")){
                    unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$acadUpdate->photo}");
                    (new Thumb())->flush($acadUpdate->photo);
                }
                $files = $_FILES["imagem"];
                $upload = new Upload();
                $image = $upload->image($files, $acadUpdate->acaNome);

                if(!$image){
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }
                $acadUpdate->photo = $image;
            }

            if(!$acadUpdate->save()){
                $json["message"] = $acadUpdate->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Academia atualizado com sucesso...")->flash();
            echo json_encode(["redirect" => url("/app/academiaAdd/{$acadUpdate->id}")]);
            return;
        }

        $acaEdit = null;
        $acaEditFoto = null;

        if(!empty($data["aca_id"])){
            $acaId = filter_var($data["aca_id"], FILTER_VALIDATE_INT);

            if(!$acaId){
                $this->message->warning("A Academia selecionada não existe ou não pertense a sua base de dados!")->flash();
                redirect("/app/academias");
            }
            $acaEdit = (new Academias())->findById($acaId);
            if(Auth::user()->id != $acaEdit->user_id && Auth::user()->level < 5){
                $this->message->error("Você não tem permissão para edita Academia...")->flash();
                redirect("/app/academias");
                return;
            }
        }

        $head = $this->seo->render(
            "Cadastrar nova Academias - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/app/academiaAdd"),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("profeAcademiaAdd", [
            "head" => $head,
            "user" => $this->user,
            "getCidades" => $this->getAllCidades(),
            "acaPhoto" => $acaEditFoto,
            "acaEdit" => $acaEdit
        ]);
    }

    public function karate(?array $data): void
    {
        if(!empty($data['del_aluno'])){
            $karatecaDelete = (new Karatecas())->find("id = :id","id={$data['del_aluno']}",
                "id, user_id")->fetch();

            if(!$karatecaDelete){
                $this->message->warning("Oops, Karateca não existe ou você não tem permição para remove-lo!")->flash();
                echo json_encode(["redirect" => url("/app/karatecas")]);
                return;
            }


            if($this->user->id != $karatecaDelete->user_id){
                $this->message->warning("Oops, Você não tem permissão para deletar este aluno(a)!")->flash();
                echo json_encode(["redirect" => url("/app/karatecas")]);
                return;
            }

            $karatecaDelete->destroy();
            $this->message->success("Ok Karateca removido com sucesso")->flash();
            echo json_encode(["redirect" => url("/app/karatecas")]);
            return;
        }


        //SEARCH Redirect
        if(!empty($data["s"])){
            $s = str_search($data["s"]);
            echo json_encode(["redirect" => url("/app/karatecas/{$s}/1")]);
            return;
        }

        $search = null;
        $karatecas = (new Karatecas())->findJoin("karateca.user_id = :id AND karateca.del = :del","id={$this->user->id}&del=0'",
            "
            karateca.id, karateca.aca_id, karateca.user_id, karateca.aluNome, karateca.aluSexo, karateca.status, 
            karateca.dtfim, karateca.dtNasc, karateca.aluTipo, 
            academia.acaNome AS academia_nome, faixa.faixaNome AS faixa_name,
            (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = :id) AS total_alunos,
            (SELECT COUNT(certificado.id) FROM certificado WHERE certificado.alu_id = karateca.id) AS total_certificado,
            (SELECT COUNT(ranking.id) FROM ranking WHERE ranking.alu_id = karateca.id) AS total_participa_eventos",
            " 
            LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id
            LEFT JOIN academia ON academia.id = karateca.aca_id");

        if(!empty($data["search"]) && str_search($data["search"]) != "all"){
            $search = str_search($data["search"]);
            $karatecas = (new Karatecas())
                ->findJoin("karateca.user_id = :id AND karateca.aluNome LIKE :s",
                    "id={$this->user->id}&s=%{$search}%",
                    "
            karateca.id, 
            karateca.aca_id, 
            karateca.user_id, 
            karateca.aluNome, 
            karateca.aluSexo, 
            karateca.status, 
            karateca.dtfim, 
            karateca.dtNasc, 
            karateca.aluTipo,
            academia.acaNome AS academia_nome,
            faixa.faixaNome AS faixa_name,
            (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = :id) AS total_alunos",
                    " 
            LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id
            LEFT JOIN academia ON academia.id = karateca.aca_id");

            if(!$karatecas->count()){
                $this->message->info("Sua pesquisa não retornou resultados!")->flash();
                redirect("/app/karatecas");
            }
        }

        $total_de_alunos = (new Karatecas())->find("user_id = :id AND del = :del",
            "id={$this->user->id}&del=0", "
                COUNT(*) as total_alunos2,
                (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = :id AND status = '1' AND dtfim >= NOW()) AS alunos_ativos, 
                (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = :id AND (status = '2' OR dtfim <= NOW())) AS alunos_inativos, 
                (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = :id) AS total_alunos
            ")->fetch();


        $all = ($search ?? "all");
        $pager = new Pager(url("/app/karatecas/{$all}/"));
        $pager->pager($karatecas->count(), 20, (!empty($data["page"]) ? $data["page"] : 1));

        $head = $this->seo->render(
            "Meus alunos registrados - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/app/karatecas"),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("profeKaratecas", [
            "head" => $head,
            "user" => $this->user,
            "search" => $search,
            "total_alunos2" => $total_de_alunos,
            "karatecas" => $karatecas->order("aluNome, id, faixa_id")->limit($pager->limit())->offset($pager->offset())->fetch(true),
            "paginator" => $pager->render()
        ]);
    }


    public function karatecaAdd(?array $data):void
    {

        $alunoEditar = null;

        if(!empty($data['edit_id'])) {

            $alunoEditar = (new Karatecas())->findJoin("karateca.id = :id AND karateca.del = :del","id={$data['edit_id']}&del=0'",
                "
            karateca.id, karateca.aca_id, karateca.user_id, karateca.aluNome, karateca.aluSexo, karateca.status, karateca.dtNasc, karateca.aluPai, karateca.aluMae, karateca.faixa_id,
            karateca.user_id, karateca.idKata, karateca.idKumite, karateca.cpf, karateca.celu,
            faixa.faixaNome",
                " LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id")->fetch();


            if ($this->user->id != $alunoEditar->user_id) {
                $this->message->info("Oops, Você não tem permissão para editar este aluno(a)!")->flash();
                redirect("/app/karatecas");
            }
        }



        if(!empty($data['createKarateca'])) {
            list($d, $m, $y) = explode("/", $data["aluNasc"]);

            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

            if($data['acaId'] === "Selecione a Academia"){
                $this->message->warning("Desculpe Professor, você não não pode cadastrar um aluno(a) sem direciona-lo a uma academia!")->flash();
                echo json_encode(["redirect" => url("/app/karatecaAdd")]);
                return;
            }

            $academiaAluno = (new Academias())->find("id = :id", "id={$data['acaId']}", "acaTipo")->fetch();

            $alunoAdd = (new Karatecas());
            $alunoAdd->user_id = $data["user_id"];
            $alunoAdd->aca_id = $data["acaId"];
            $alunoAdd->aluTipo = $academiaAluno->acaTipo;
            $alunoAdd->aluNome = $data["aluNome"];

            $alunoAdd->celu = preg_replace("/[^0-9]/", "", $data["celu"]);
            $alunoAdd->aluMae = $data["aluMae"];
            $alunoAdd->aluPai = $data["aluPai"];
            $alunoAdd->status = '2';
            $alunoAdd->faixa_id = '2';
            $alunoAdd->aluSexo = $data["aluSexo"];
            $alunoAdd->dtNasc = "{$y}-{$m}-{$d}";
            $alunoAdd->idKata = $data["kata"];
            $alunoAdd->idKumite = $data["kumite"];
            $alunoAdd->dtCadastro = date("Y-m-d H:i:s");

            //CPF opcional
            if(!empty($data["cpf"])){

                $alunoAdd->cpf = preg_replace("/[^0-9]/", "", $data["cpf"]);
                if(!validaCPF($alunoAdd->cpf)){
                    $json["message"] = $this->message->error("Não é permitido inscrever aluno com CPF inválido \"{$alunoAdd->cpf}\".  Não foi  cadastrado(a)!")->render();
                    echo json_encode($json);
                    return;
                }

                $compareCpfCadastrado = (new Karatecas())->find("cpf = :e","e={$alunoAdd->cpf}")->fetch();

                if (!empty($compareCpfCadastrado)) {
                    $json["message"] = $this->message->error("O CPF informado <input class=\"mask-doc\">{$alunoAdd->cpf}</input> já está cadastrado, Solicite ajuda via suporte, no canto superior direito do painel.")->render();
                    echo json_encode($json);
                    return;
                }
            }


            if(!$alunoAdd->save()){
                $json["message"] = $alunoAdd->message()->render();
                echo json_encode($json);
                return;
            }


            $this->message->success("Karateca cadastrado(a) com Sucesso...")->flash();
            echo json_encode(["redirect" => url("/app/karatecas/{$alunoAdd->aluNome}/1")]);
            return;
        }

        if(!empty($data["UpdateKarateca"])){


            list($d, $m, $y) = explode("/", $data["aluNasc"]);

            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

            $alunoEditar = (new Karatecas())->find("id = :id","id={$data['alu_id']}",
                "id, aca_id, user_id")->fetch();

            if ($this->user->id != $alunoEditar->user_id) {
                $this->message->info("Oops, Você não tem permissão para editar este aluno(a)!")->flash();
                redirect("/app/karatecas");
            }

            if($data['acaId'] === "Selecione a Academia"){
                $this->message->warning("Desculpe Professor, você não não pode cadastrar um aluno(a) sem direciona-lo a uma academia!")->flash();
                echo json_encode(["redirect" => url("/app/karatecaAdd")]);
                return;
            }

            $academiaAluno = (new Academias())->find("id = :id", "id={$data['acaId']}", "acaTipo")->fetch();

            $alunoEditar->user_id = $data["user_id"];
            $alunoEditar->aca_id = $data["acaId"];
            $alunoEditar->aluTipo = $academiaAluno->acaTipo;
            $alunoEditar->aluNome = $data["aluNome"];

            $alunoEditar->celu = preg_replace("/[^0-9]/", "", $data["celu"]);
            $alunoEditar->aluMae = $data["aluMae"];
            $alunoEditar->aluPai = $data["aluPai"];
            $alunoEditar->aluSexo = $data["aluSexo"];
            $alunoEditar->dtNasc = "{$y}-{$m}-{$d}";
            $alunoEditar->idKata = $data["kata"];
            $alunoEditar->idKumite = $data["kumite"];


            //CPF opcional
            if(!empty($data["cpf"])) {

                $alunoEditar->cpf = preg_replace("/[^0-9]/", "", $data["cpf"]);

                if (!validaCPF($alunoEditar->cpf)) {
                    $json["message"] = $this->message->error("Não é permitido inscrever aluno com CPF inválido \"{$alunoEditar->cpf}\".  Não foi  cadastrado(a)!")->render();
                    echo json_encode($json);
                    return;
                }

                $compareCpfCadastrado = (new Karatecas())->find("cpf = :e AND id != :i", "e={$alunoEditar->cpf}&i={$data['alu_id']}")->fetch();

                if (!empty($compareCpfCadastrado)) {
                    $json["message"] = $this->message->error("O CPF informado <input class=\"mask-doc\">{$alunoEditar->cpf}</input> já está cadastrado, Solicite ajuda via suporte, no canto superior direito do painel.")->render();
                    echo json_encode($json);
                    return;
                }
            }


            if(!$alunoEditar->save()){
                $json["message"] = $alunoEditar->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Karateca {$alunoEditar->aluNome} atualizado com sucesso...")->flash();
            echo json_encode(["redirect" => url("/app/karatecaAdd/{$alunoEditar->id}")]);
            return;
        }

        $academias = (new Academias())->find("user_id = :id","id={$this->user->id}", "
         academia.id, academia.acaNome, academia.acaTipo")->fetch(true);

        $categorias = (new Categorias())->find()->fetch(true);

        $head = $this->seo->render(
            "Meus alunos registrados - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/app/karatecaAdd"),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("profeKaratecasAdd", [
            "head" => $head,
            "user" => $this->user,
            "academias" => $academias,
            "faixas_list" => $this->getAllFaixa(),
            "categorias" => $categorias,
            "editarAluno" => $alunoEditar,
            "faixaAtual" => $this->getAllFaixa()
        ]);
    }


    public function ativarKarateca(?array $data): void
    {
        $user = $data['user_id'];
        $aca = $data['aca_id'];


        if(!empty($data["action"]) && $data["action"] == "cadAtivar") {
            if(empty($data["addKaratecas"])) {
                $this->message->info("Selecione o aluno para ativar anuidade.")->flash();
                echo json_encode(["redirect" => url("/app/ativarkarateca/{$aca}/{$user}")]);
                return;
            }

            foreach ($data["addKaratecas"] as $key => $value) {
                $karatecasCertifi = (new Karatecas())->findJoin("id = :id","id={$value}", "id, aluNome, faixa_id, aluTipo,
                    faixa.valor, faixa.valorProj",
                    "LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id")->fetch();

                if($karatecasCertifi->aluTipo == 1){
                    $valorAnuidade = $karatecasCertifi->valor;
                }else{
                    $valorAnuidade = $karatecasCertifi->valorProj;
                }

                $ativar_aulumo = (new Ativar_Aluno());
                $ativar_aulumo->user_id = $user;
                $ativar_aulumo->aca_id = $aca;
                $ativar_aulumo->alu_id = $karatecasCertifi->id;
                $ativar_aulumo->dataSoli = date("Y-m-d H:i:s");
                $ativar_aulumo->status = 3;
                $ativar_aulumo->faixaAtual = $karatecasCertifi->faixa_id;
                $ativar_aulumo->valor = $valorAnuidade;
                $ativar_aulumo->tipo = $karatecasCertifi->aluTipo;

                if(!$ativar_aulumo->save()){
                    echo 'Entrou no erro';
                    $json["message"] = $ativar_aulumo->message()->render();
                    echo json_encode($json);
                    return;
                }
            }
            $this->message->info("Ativação dos alunos selecionados foi solicitada com sucesso, por favor efetue o pagamento e informe a FEKTO para ativação!")->flash();
            echo json_encode(["redirect" => url("/app/ativarkarateca/{$aca}/{$user}")]);
            return;
        }

        if(!empty($data["action"]) && $data["action"] == "delAtivar"){


            if(empty($data["delKaratecas"])) {
                $this->message->info("Selecione atletas para remover desta solicitação de ativação.")->flash();
                echo json_encode(["redirect" => url("/app/ativarkarateca/{$aca}/{$user}")]);
                return;
            }


            foreach ($data["delKaratecas"] as $key => $value) {
                $delAtivarcao = (new Ativar_Aluno())->findById($value);
                $delAtivarcao->destroy();
            }
            $this->message->success("Atleta(s) removido da solicitação de ativação da anuidade. Oss")->flash();
            echo json_encode(["redirect" => url("/app/ativarkarateca/{$aca}/{$user}")]);
            return;
        }

        $academia = (new Academias())->findById($data["aca_id"]);

        if(!$academia){
            $this->message->warning("Essa academia não existe, por favor selecione uma academia de sua base de dados!")->flash();
            redirect("/app&ativarkaratecas=true");
        }

        if($academia->user_id != User()->id){
            $this->message->warning("Erro: 180: Essa academia não existe, por favor selecione uma academia de sua base de dados!")->flash();
            redirect("/app&ativarkaratecas=true");
        }

        $karatecas = (new Karatecas())->findJoin("karateca.user_id = :id AND karateca.aca_id = :aca_id AND karateca.del = :del 
                AND karateca.id 
                AND (karateca.dtfim <= NOW() OR karateca.status <> 1) AND karateca.id NOT IN (SELECT alu_id FROM ativar_aluno WHERE status = '3'    )",
            "id={$this->user->id}&del=0&aca_id={$academia->id}'",
            "
            karateca.*, 
            faixa.faixaNome AS faixa_name, faixa.valor AS valor_normal, faixa.valorProj AS valor_projeto,
            (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = :id AND karateca.aca_id = :aca_id) AS total_alunos",
            " LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id
                ")->order("status, aluNome, aluTipo")->fetch(true);



//        var_dump($karatecas);
//karateca.id, karateca.aca_id, karateca.user_id, karateca.aluNome, karateca.aluSexo, karateca.status, karateca.faixa_id,
//            karateca.dtfim, karateca.dtNasc, karateca.aluTipo
//NOT IN (SELECT alu_id FROM certificado WHERE certificado.alu_id = karateca.id AND certificado.certStatus = '3')

        $diplomasEmitir = (new Ativar_Aluno())->findJoin("ativar_aluno.user_id = :id AND ativar_aluno.status = '3' AND ativar_aluno.aca_id = :aca",
            "id={$user}&aca={$aca}",
            "ativar_aluno.*,
                karateca.aluNome, karateca.dtfim, karateca.aluSexo, karateca.status, karateca.faixa_id,
                faixa.faixaNome AS faixa_name",
            "
                LEFT JOIN karateca ON karateca.id = ativar_aluno.alu_id
                LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id")->fetch(true);

        // id, ativar_aluno.alu_id, ativar_aluno.aca_id, ativar_aluno.valor, ativar_aluno.status, ativar_aluno.faixaAtual, ativa_aluno.dataSoli
        //var_dump($diplomasEmitir);

        $head = $this->seo->render(
            "Solicitar diplomas de faixa - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/app/ativarkarateca"),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("professor/ativarKarateca", [
            "head" => $head,
            "user" => $this->user,
            "academia" => $academia,
            "faixas_list" => $this->getAllFaixa(),
            "karatecas" => $karatecas,
            "diplomas" => $diplomasEmitir
        ]);
    }


    public function karatecaImport(?array $data): void
    {
        $aluno_import_id = null;
        $academia = null;
        $solicitacoes = null;
        $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

        if(!empty($data["action"]) && $data["action"] == "buscarId") {

            $tranferIncluso = (new Transferencia())->findJoin("alu_id = :id AND user_id = :user AND status = '2'",
                "id={$data["id_aluno"]}&user={$this->user->id}")->fetch();

            if(!empty($tranferIncluso)) {
                $this->message->warning("Ooops, Caro {$this->user->fullName()}, já existe uma solicitação para este aluno em seu nome, por favor aguarde a ativação por parte da FEKTO OSS...")->flash();
                echo json_encode(["redirect" => url("/app/importarkarateca")]);
                return;
            }

            if(!empty($data["id_aluno"])){
                $aluno_import = (new Karatecas())->find("id = :i", "i={$data["id_aluno"]}")->fetch();
                if(!empty($aluno_import)){
                    if($aluno_import->user_id == $this->user->id){
                        $this->message->info("O aluno pesquisado ID {$aluno_import->id}, Nome {$aluno_import->aluNome}. já esta em sua base de dados!")->flash();
                        echo json_encode(["redirect" => url("/app/importarkarateca")]);
                        return;
                    }
                    $this->message->info("Aluno encontrado!")->render();
                    echo json_encode(["redirect" => url("/app/importarkarateca/{$aluno_import->id}")]);
                    return;
                }
            }

            if(!empty($data["cpf"])){
                $cpf = preg_replace("/[^0-9]/", "", $data["cpf"]);
                $aluno_import = (new Karatecas())->find("cpf = :i", "i={$cpf}")->fetch();
                if(!empty($aluno_import)){
                    if($aluno_import->user_id == $this->user->id){
                        $this->message->info("O aluno pesquisado ID {$aluno_import->id}, Nome {$aluno_import->aluNome}. já esta em sua base de dados!")->flash();
                        echo json_encode(["redirect" => url("/app/importarkarateca")]);
                        return;
                    }
                    $this->message->info("Aluno encontrado!")->flash();
                    echo json_encode(["redirect" => url("/app/importarkarateca/{$aluno_import->id}")]);
                    return;
                }
            }

            $this->message->info("Aluno não encontrado!")->flash();
            echo json_encode(["redirect" => url("/app/importarkarateca")]);
            return;
        }

        if(!empty($data["action"]) && $data["action"] == "cancelarTransfere") {
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
            $delTransfer = (new Transferencia())->findById($data["delTransfer"]);

            if(!$delTransfer){
                $this->message->error("Você tentou Excluir uma solicitação que não existe ou já foi removida!")->flash();
                echo json_encode(["reload" => true]);
                return;
            }

            $delTransfer->destroy();
            $this->message->success("A Solicitação de transferência de Aluno foi cancelada com sucesso")->flash();
            echo json_encode(["reload" => true]);
            return;
        }


        if(!empty($data["action"]) && $data["action"] == "solicitarImport") {
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

            if($data['acaId_destino'] === "Selecione a Academia"){
                $this->message->warning("Desculpe Professor, informe a ACADEMIA para a qual o aluno  será transferido. OSS")->flash();
                echo json_encode(["redirect" => url("/app/importarkarateca/{$data["id_aluno_confirm"]}")]);
                return;
            }

            $karatecasTransf = (new Karatecas())->findJoin("id = :id","id={$data["id_aluno_confirm"]}")->fetch();

            if(empty($karatecasTransf)) {
                $this->message->info("Erro na Solicitação, verifique os dados...")->render();
                echo json_encode(["redirect" => url("/app/importarkarateca")]);
                return;
            }
            $tranferIncluso = (new Transferencia())->findJoin("alu_id = :id AND user_id = :user AND status = 2",
                "id={$data["id_aluno_confirm"]}&user={$this->user->id}")->fetch();

            if(!empty($tranferIncluso)) {
                $this->message->warning("Ooops, Caro {$this->user->fullName()}, já existe uma solicitação para este aluno em seu nome, por favor aguarde a ativação por parte da FEKTO OSS...")->flash();
                echo json_encode(["redirect" => url("/app/importarkarateca/{$data["id_aluno_confirm"]}")]);
                return;
            }

            $academiaTransf = (new Academias())->findJoin("id = :id AND user_id = :user","id={$data["acaId_destino"]}&user={$this->user->id}")->fetch();

            if(empty($academiaTransf)) {
                $this->message->info("Erro na Solicitação, Academia com ID {$data["acaId_destino"]} não encontrada! verifique os dados...")->render();
                echo json_encode(["redirect" => url("/app/importarkarateca")]);
                return;
            }


            $tranferencia = (new Transferencia());
            $tranferencia->alu_id = $karatecasTransf->id;
            $tranferencia->aluNome = $karatecasTransf->aluNome;
            $tranferencia->user_id_oud = $karatecasTransf->user_id;
            $tranferencia->aca_id_oud = $karatecasTransf->aca_id;
            $tranferencia->user_id = $this->user->id;
            $tranferencia->aca_id = $academiaTransf->id;
            $tranferencia->status = 2;
            $tranferencia->dtCadastro = date("Y-m-d H:i:s");

            if(!$tranferencia->save()){
                echo 'Entrou no erro';
                $json["message"] = $tranferencia->message()->render();
                echo json_encode($json);
                return;
            }

        $this->message->info("Solicitação de transferência de Karateca efetuada com sucesso. Por favor efetue o pagamento da taxa de transferência e informe a FEKTO para ativação!")->flash();
        echo json_encode(["redirect" => url("/app/importarkarateca")]);
        return;
        }

        if(!empty($data["alu_url_id"])){
            $aluno_import_id = (new Karatecas())->findJoin("id = :id", "id={$data["alu_url_id"]}",
                "karateca.*,
                faixa.faixaNome AS faixa_name",
            "LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id")->fetch();

            $academia = (new Academias())->findJoin("user_id = {$this->user->id}")->fetch(true);
        }

        $solici = (new Transferencia())->findJoin("transfer_karateca.user_id = :user AND transfer_karateca.status = '2'", "user={$this->user->id}",
        "transfer_karateca.*,
            karateca.id AS aluno_id, karateca.aluNome, karateca.dtNasc, karateca.dtfim, karateca.aluSexo, karateca.cpf, karateca.celu, karateca.faixa_id,
            faixa.faixaNome,
            (SELECT COUNT(certificado.id) FROM certificado WHERE certificado.alu_id = karateca.id) AS total_certificado,
            (SELECT COUNT(ranking.id) FROM ranking WHERE ranking.alu_id = karateca.id) AS total_participa_eventos",
            " LEFT JOIN karateca ON karateca.id = transfer_karateca.alu_id
                   LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id")->fetch(true);

        if(!empty($solici)){
            $solicitacoes = $solici;
        }

        $head = $this->seo->render(
            "Solicitar de transferência de Karateca - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/app/importarkarateca"),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("professor/karatecaImporte", [
            "head" => $head,
            "user" => $this->user,
            "aluno_import" => $aluno_import_id,
            "academias" => $academia,
            "solicitacoes" => $solicitacoes
        ]);
    }


    /******************************************
                    AREA DE IMPRESSAO PDF
    ******************************************/
    public function certificadoPdf(?array $data): void
    {

        $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

        $professor = (new User())->find("id = :id", "id={$data['user_id']}", "id, name, sobrenome, email, end, fone, margem")->fetch();
        if (!$professor) {
            $this->message->info("Erro ao gerar PDF, solicitação de certificado inexistente ou recentemente deletadas!")->flash();
            redirect("/app");
        }

        $academiaPdf = (new Academias())->find("id = :id", "id={$data['aca_id']}", "id, acaNome, acaEnd")->fetch();
        if (!$academiaPdf) {
            $this->message->info("Erro ao gerar PDF, solicitação de certificado inexistente ou recentemente deletadas!")->flash();
            redirect("/app");
        }

        $diplomasEmitir = (new Certificados())->findJoin("certificado.user_id = :id AND certificado.certStatus = '3' AND certificado.aca_id = :aca",
            "id={$data['user_id']}&aca={$data['aca_id']}",
            "certificado.id, certificado.user_id, certificado.alu_id, certificado.aca_id, certificado.valorExame, certificado.certStatus, certificado.faixaId, faixaIdAnterio, certiDt,
                karateca.aluNome, karateca.dtfim, karateca.aluSexo, karateca.status, karateca.dtNasc,
                faixa.faixaNome AS faixa_name,
                faixaCert.faixaNome AS faixa_nameProxi, faixaCert.valorExame AS valorExameProxima, faixaCert.valorExameProj AS valorExameProjProxima",
            "
                LEFT JOIN karateca ON karateca.id = certificado.alu_id
                LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id
                LEFT JOIN faixa AS faixaCert ON faixaCert.faixaId = certificado.faixaId
            ")->fetch(true);

        if (!$diplomasEmitir) {
            $this->message->info("Erro ao gerar PDF, solicitação de certificado inexistente ou recentemente deletadas!")->flash();
            redirect("/app");
        }

        $empresa = (new Empresa())->find()->fetch();

        $mpdf = new \Mpdf\Mpdf([
            'margin_left' => 5,
            'margin_right' => 5,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_header' => 10,
            'margin_footer' => 10
        ]);
        $mesNow = date("m");

//        $mpdf->SetProtection(array('print'));
        $mpdf->SetTitle("diploma.{$professor->name}.{$academiaPdf->acaNome}.pedido-{$mesNow}");
        $mpdf->SetAuthor("{$professor->name} {$professor->sobrenome}");
        $mpdf->SetWatermarkText("FEKTO");
        $mpdf->showWatermarkText = true;
//        $mpdf->watermark_font = 'DejaVuSansCondensed';
        $mpdf->watermarkTextAlpha = 0.1;
        $mpdf->SetDisplayMode('fullpage');


        require __DIR__ . "/../../themes/app/print/certificado.php";
//        $mpdf->WriteHTML($css, 2);
        $mpdf->WriteHTML(ob_get_clean());


//        $mpdf->Output('RELATÓRIO.pdf', "D");
        $mpdf->Output("diploma.{$professor->name}.{$academiaPdf->acaNome}.pedido-{$mesNow}.pdf", "D");
//        $mpdf->Output();
    }


    public function financProPdf(?array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

        $professor = (new User())->findJoin("id = :id", "id={$data['user_id']}",
            "id, name, sobrenome, email, end, fone, dtfim,
            faixa.valor",
            "LEFT JOIN faixa ON faixa.faixaId = '100'")->fetch();
        if (!$professor) {
            $this->message->info("Erro ao gerar PDF, Professor inexistente!")->flash();
            redirect("/app");
        }

        $empresa = (new Empresa())->find()->fetch();


        $mpdf = new \Mpdf\Mpdf([
            'margin_left' => 5,
            'margin_right' => 5,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_header' => 10,
            'margin_footer' => 10
        ]);

//        $mpdf->SetProtection(array('print'));
        $mpdf->SetTitle("Ativação_de_.{$professor->name}");
        $mpdf->SetAuthor("{$professor->name} {$professor->sobrenome}");
        $mpdf->SetWatermarkText("FEKTO");
        $mpdf->showWatermarkText = true;
//        $mpdf->watermark_font = 'DejaVuSansCondensed';
        $mpdf->watermarkTextAlpha = 0.1;
        $mpdf->SetDisplayMode('fullpage');


        require __DIR__ . "/../../themes/app/print/professorUnit.php";
//        $mpdf->WriteHTML($css, 2);
        $mpdf->WriteHTML(ob_get_clean());


//        $mpdf->Output('RELATÓRIO.pdf', "D");
        $mpdf->Output("Anuidade_prof.{$professor->name} {$professor->sobrenome}.pdf", "D");
//        $mpdf->Output();
    }

    /*Ared do professor*/
    public function profalunosinativos(?array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

        $professor = (new User())->find("id = :id", "id={$this->user->id}", "id, name, sobrenome, email, end, fone, margem")->fetch();
        if (!$professor) {
            $this->message->info("Erro ao gerar PDF, solicitação de certificado inexistente ou recentemente deletadas!")->flash();
            redirect("/app");
        }

        $karatecas = (new Karatecas())->findJoin("karateca.user_id = :id AND karateca.del = '0' AND (karateca.dtfim < NOW() OR karateca.status <> 1)",
            "id={$this->user->id}",
            "
            karateca.*, 
            faixa.faixaNome AS faixa_name, faixa.valor AS valor_normal, faixa.valorProj AS valor_projeto,
            academia.id AS aca_id, academia.acaNome AS aca_nome, academia.acaTipo as acaTipo",
            " LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id
                  LEFT JOIN academia ON academia.id = karateca.aca_id
                ")->order("status, aluNome, aluTipo")->fetch(true);

        $empresa = (new Empresa())->find()->fetch();

        $mpdf = new \Mpdf\Mpdf([
            'margin_left' => 5,
            'margin_right' => 5,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_header' => 10,
            'margin_footer' => 10
        ]);
        $mesNow = date("dm");

//        $mpdf->SetProtection(array('print'));
        $mpdf->SetTitle("Alunos_Inativos_prof.{$professor->name}.pedido-{$mesNow}");
        $mpdf->SetAuthor("{$professor->name}");
        $mpdf->SetWatermarkText("FEKTO");
        $mpdf->showWatermarkText = true;
//        $mpdf->watermark_font = 'DejaVuSansCondensed';
        $mpdf->watermarkTextAlpha = 0.1;
        $mpdf->SetDisplayMode('fullpage');


        require __DIR__ . "/../../themes/app/print/profalunosinativos.php";
//        $mpdf->WriteHTML($css, 2);
        $mpdf->WriteHTML(ob_get_clean());


//        $mpdf->Output('RELATÓRIO.pdf', "D");
        $mpdf->Output("Alunos_Inativos_prof-{$professor->name}pedido-{$mesNow}.pdf", "D");
//        $mpdf->Output();
    }


    public function profalunosativos(?array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

        $professor = (new User())->find("id = :id", "id={$this->user->id}", "id, name, sobrenome, email, end, fone, margem")->fetch();
        if (!$professor) {
            $this->message->info("Erro ao gerar PDF, solicitação de certificado inexistente ou recentemente deletadas!")->flash();
            redirect("/app");
        }

        $karatecas = (new Karatecas())->findJoin("karateca.user_id = :id AND karateca.del = '0' AND karateca.dtfim > NOW() AND karateca.status = '1'",
            "id={$this->user->id}",
            "
            karateca.*, 
            faixa.faixaNome AS faixa_name, faixa.valor AS valor_normal, faixa.valorProj AS valor_projeto,
            academia.id AS aca_id, academia.acaNome AS aca_nome",
            " LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id
                  LEFT JOIN academia ON academia.id = karateca.aca_id
                ")->order("status, aluNome, aluTipo")->fetch(true);

        $empresa = (new Empresa())->find()->fetch();

        $mpdf = new \Mpdf\Mpdf([
            'margin_left' => 5,
            'margin_right' => 5,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_header' => 10,
            'margin_footer' => 10
        ]);
        $mesNow = date("dm");

//        $mpdf->SetProtection(array('print'));
        $mpdf->SetTitle("Alunos_Ativos_prof.{$professor->name}.pedido-{$mesNow}");
        $mpdf->SetAuthor("{$professor->name}");
        $mpdf->SetWatermarkText("FEKTO");
        $mpdf->showWatermarkText = true;
//        $mpdf->watermark_font = 'DejaVuSansCondensed';
        $mpdf->watermarkTextAlpha = 0.1;
        $mpdf->SetDisplayMode('fullpage');


        require __DIR__ . "/../../themes/app/print/profalunosativos.php";
//        $mpdf->WriteHTML($css, 2);
        $mpdf->WriteHTML(ob_get_clean());


//        $mpdf->Output('RELATÓRIO.pdf', "D");
        $mpdf->Output("Alunos_Ativos_prof-{$professor->name}pedido-{$mesNow}.pdf", "D");
//        $mpdf->Output();
    }


    public function profalunostodos(?array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

        $professor = (new User())->find("id = :id", "id={$this->user->id}", "id, name, sobrenome, email, end, fone, margem")->fetch();
        if (!$professor) {
            $this->message->info("Erro ao gerar PDF, solicitação de certificado inexistente ou recentemente deletadas!")->flash();
            redirect("/app");
        }


        $karatecas = (new Karatecas())->findJoin("karateca.user_id = :id AND karateca.del = '0'",
            "id={$this->user->id}",
            "
            karateca.*, 
            faixa.faixaNome AS faixa_name, faixa.valor AS valor_normal, faixa.valorProj AS valor_projeto,
            academia.id AS aca_id, academia.acaNome AS aca_nome",
            " LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id
                  LEFT JOIN academia ON academia.id = karateca.aca_id
                ")->order("status, aluNome, aluTipo")->fetch(true);

        $empresa = (new Empresa())->find()->fetch();

        $mpdf = new \Mpdf\Mpdf([
            'margin_left' => 5,
            'margin_right' => 5,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_header' => 10,
            'margin_footer' => 10
        ]);
        $mesNow = date("dm");

//        $mpdf->SetProtection(array('print'));
        $mpdf->SetTitle("Alunos_Ativos_prof.{$professor->name}.pedido-{$mesNow}");
        $mpdf->SetAuthor("{$professor->name}");
        $mpdf->SetWatermarkText("FEKTO");
        $mpdf->showWatermarkText = true;
//        $mpdf->watermark_font = 'DejaVuSansCondensed';
        $mpdf->watermarkTextAlpha = 0.1;
        $mpdf->SetDisplayMode('fullpage');


        require __DIR__ . "/../../themes/app/print/profalunostodos.php";
//        $mpdf->WriteHTML($css, 2);
        $mpdf->WriteHTML(ob_get_clean());


//        $mpdf->Output('RELATÓRIO.pdf', "D");
        $mpdf->Output("Alunos_Ativos_prof-{$professor->name}pedido-{$mesNow}.pdf", "D");
//        $mpdf->Output();
    }


    public function profcertificadotodos(?array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

        $professor = (new User())->find("id = :id", "id={$this->user->id}", "id, name, sobrenome, email, end, fone, margem")->fetch();
        if (!$professor) {
            $this->message->info("Erro ao gerar PDF, solicitação de certificado inexistente ou recentemente deletadas!")->flash();
            redirect("/app");
        }


        $karatecas = (new Certificados())->findJoin("certificado.user_id = :user_id AND certificado.certStatus = '1'",
            "user_id={$this->user->id}",
            "certificado.*,
            faixaProxima.faixaNome AS faixa_proxima,
            academia.acaNome",
            "
            INNER JOIN faixa faixaProxima ON faixaProxima.faixaId = certificado.faixaId
            LEFT JOIN academia ON academia.id = certificado.aca_id"
        )->order("aluNome ASC")->fetch(true);

        $empresa = (new Empresa())->find()->fetch();

        $mpdf = new \Mpdf\Mpdf([
            'margin_left' => 5,
            'margin_right' => 5,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_header' => 10,
            'margin_footer' => 10
        ]);
        $mesNow = date("dm");

//        $mpdf->SetProtection(array('print'));
        $mpdf->SetTitle("Certificados_Alunos_prof.{$professor->name}.pedido-{$mesNow}");
        $mpdf->SetAuthor("{$professor->name}");
        $mpdf->SetWatermarkText("FEKTO");
        $mpdf->showWatermarkText = true;
//        $mpdf->watermark_font = 'DejaVuSansCondensed';
        $mpdf->watermarkTextAlpha = 0.1;
        $mpdf->SetDisplayMode('fullpage');


        require __DIR__ . "/../../themes/app/print/profcertificadotodos.php";
//        $mpdf->WriteHTML($css, 2);
        $mpdf->WriteHTML(ob_get_clean());


//        $mpdf->Output('RELATÓRIO.pdf', "D");
        $mpdf->Output("Certificados_Alunos_prof-{$professor->name}pedido-{$mesNow}.pdf", "D");
//        $mpdf->Output();
    }


    public function alunoAnuidadePdf(?array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

        $professor = (new User())->find("id = :id", "id={$data['user_id']}", "id, name, sobrenome, email, end, fone, margem")->fetch();
        if (!$professor) {
            $this->message->info("Erro ao gerar PDF, solicitação de certificado inexistente ou recentemente deletadas!")->flash();
            redirect("/app");
        }

        $academiaPdf = (new Academias())->find("id = :id", "id={$data['aca_id']}", "id, acaNome, acaEnd, acaCadastro")->fetch();
        if (!$academiaPdf) {
            $this->message->info("Erro ao gerar PDF, solicitação de certificado inexistente ou recentemente deletadas!")->flash();
            redirect("/app");
        }

        $anuidadeAluno = (new Ativar_Aluno())->findJoin("ativar_aluno.user_id = :id AND ativar_aluno.status = '3' AND ativar_aluno.aca_id = :aca",
            "id={$data['user_id']}&aca={$data['aca_id']}",
            "ativar_aluno.*,
                karateca.aluNome, karateca.dtfim, karateca.aluSexo, karateca.status, karateca.faixa_id, karateca.dtNasc, karateca.dtCadastro,
                faixa.faixaNome AS faixa_name",
            "
                LEFT JOIN karateca ON karateca.id = ativar_aluno.alu_id
                LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id")->fetch(true);


        if (!$anuidadeAluno) {
            $this->message->info("Erro ao gerar PDF, solicitação de certificado inexistente ou recentemente deletadas!")->flash();
            redirect("/app");
        }

        $empresa = (new Empresa())->find()->fetch();

        $mpdf = new \Mpdf\Mpdf([
            'margin_left' => 5,
            'margin_right' => 5,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_header' => 10,
            'margin_footer' => 10
        ]);
        $mesNow = date("m");

//        $mpdf->SetProtection(array('print'));
        $mpdf->SetTitle("diploma.{$professor->name}.{$academiaPdf->acaNome}.pedido-{$mesNow}");
        $mpdf->SetAuthor("{$professor->name} {$professor->sobrenome}");
        $mpdf->SetWatermarkText("FEKTO");
        $mpdf->showWatermarkText = true;
//        $mpdf->watermark_font = 'DejaVuSansCondensed';
        $mpdf->watermarkTextAlpha = 0.1;
        $mpdf->SetDisplayMode('fullpage');


        require __DIR__ . "/../../themes/app/print/aluno.php";
//        $mpdf->WriteHTML($css, 2);
        $mpdf->WriteHTML(ob_get_clean());


//        $mpdf->Output('RELATÓRIO.pdf', "D");
        $mpdf->Output("Anuidade_Alunos_prof.{$professor->name}._academia_{$academiaPdf->acaNome}.pedido-{$mesNow}.pdf", "D");
//        $mpdf->Output();
    }


    public function camp(?array $data): void
    {
        $cam = $data['cam'];
        $aca = $data['aca'];
//        var_dump($data);

        if(!empty($data["action"]) && $data["action"] == "cadAlunos") {

//            echo "ok entramos aqui";
            if(empty($data["addKaratecas"])) {
                $this->message->info("Selecione atleta(s) para cadastrar neste evento.")->flash();
                echo json_encode(["redirect" => url("/app/campeonatoAdd/{$aca}/{$cam}")]);
                return;
            }
            $postAluno = $_POST["addKaratecas"];

            $user = $data['user_id'];
            $valor = $data['valor'];

            foreach ($data["addKaratecas"] as $key => $value) {
                $karatecasRank = (new Karatecas())->find("id = :id","id={$value}'",
                    "id, idKumite, idKata")->fetch();

                $rankingAdd = (new Ranking());
                $rankingAdd->cam_id = $cam;
                $rankingAdd->alu_id = $value;
                $rankingAdd->user_id = $user;
                $rankingAdd->aca_id = $aca;
                $rankingAdd->valor = $valor;
                $rankingAdd->idKumite = ($karatecasRank->idKumite < 1)? '500' : $karatecasRank->idKumite;
                $rankingAdd->idKata   = ($karatecasRank->idKata < 1)? '500' : $karatecasRank->idKata;
                $rankingAdd->status = 2;
                $rankingAdd->dt = date("Y-m-d H:i:s");

                if(!$rankingAdd->save()){
                    $json["message"] = $rankingAdd->message()->render();
                    echo json_encode($json);
                    return;
                }
            }
            $this->message->success("Atletas cadastrados com sussece neste evento. Oss")->flash();
            echo json_encode(["redirect" => url("/app/campeonatoAdd/{$aca}/{$cam}")]);
            return;
        }

        if(!empty($data["action"]) && $data["action"] == "delAluCamp"){
            if(empty($data["delKaratecas"])) {
                $this->message->info("Selecione atletas para remover do evento.")->flash();
                echo json_encode(["redirect" => url("/app/campeonatoAdd/{$aca}/{$cam}")]);
                return;
            }
            $user = $data['user_id'];
            foreach ($data["delKaratecas"] as $key => $value) {
                $rankingDel = (new Ranking())->findById($value);
                $rankingDel->destroy();
            }
            $this->message->success("Atletas removido do evento com sussece. Oss")->flash();
            echo json_encode(["redirect" => url("/app/campeonatoAdd/{$aca}/{$cam}")]);
            return;

        }


        $academia = (new Academias())->findById($data["aca"]);
        $cam = null;

        if(!$academia){
            $this->message->warning("Essa academia não existe, por favor selecione uma academia de sua base de dados!")->flash();
            redirect("/app");
        }

        $cam = (new Post())->findById($data["cam"]);
        if(!$cam){
            $this->message->warning("Esse campeonato selecionado não existe, por favor selecione o evento clicando no respequitivo campeonato!")->flash();
            redirect("/app");
        }

        $camCompara = (new Post())->find("category = '2' AND camStatus = '1' AND dtevento >= NOW() AND del = '0'","", "id")->order("dtevento ASC")->fetch();

        if($cam->id != $camCompara->id){
            $this->message->warning("Por favor, Selecione corretamente o evento para o cadastro de alunos...");
            redirect("/app");
        }

        $karatecas = (new Karatecas())
            ->findJoin("karateca.user_id = :id AND karateca.aca_id = :aca_id AND karateca.del = :del AND karateca.id NOT IN (SELECT alu_id FROM ranking WHERE cam_id = :cam_id)",
                "id={$this->user->id}&del=0&aca_id={$academia->id}&cam_id={$cam->id}'",
                "
            karateca.id, karateca.aca_id, karateca.user_id, karateca.aluNome, karateca.aluSexo, karateca.status, karateca.idKumite, karateca.idKata,
            karateca.dtfim, karateca.dtNasc, karateca.aluTipo, faixa.faixaNome AS faixa_name,
            (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = :id AND karateca.aca_id = :aca_id) AS total_alunos",
                " LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id")->order("dtfim DESC, aluNome, aluTipo, status DESC")->fetch(true);

        $list_tabela_Ranking = (new Ranking())->findJoin("ranking.user_id = :id AND ranking.aca_id = :aca_id AND ranking.cam_id = :cam_id",
            "id={$this->user->id}&aca_id={$data['aca']}&cam_id={$data['cam']}",
            "
                ranking.id, ranking.alu_id, ranking.aca_id, ranking.cam_id, ranking.valor,
                karateca.aluNome, karateca.dtfim, karateca.aluSexo, karateca.status, karateca.faixa_id, karateca.aluTipo, karateca.idKumite, karateca.idKata,
                faixa.faixaNome AS faixa_name",
            "
                LEFT JOIN karateca
                ON karateca.id = ranking.alu_id
                LEFT JOIN faixa
                ON faixa.faixaId = karateca.faixa_id")->fetch(true);

        if(!$list_tabela_Ranking){
            $list_tabela_Ranking = null;
        }

        $head = $this->seo->render(
            "Cadastrar alunos neste evento - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/app/campeonatoAdd"),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("profeCampeonato", [
            "head" => $head,
            "user" => $this->user,
            "getCidades" => $this->getAllCidades(),
            "campeonato" => $cam,
            "karatecas" => $karatecas,
            "getAcademia" => $academia,
            "faixas_list" => $this->getAllFaixa(),
            "list_tabela_Ranking" => $list_tabela_Ranking
        ]);
    }


    public function campProfPDF(?array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

//        var_dump($data);

        $professor = (new User())->find("id = :id", "id={$data['user_id']}", "id, name, sobrenome, email, end, fone, margem")->fetch();
        if (!$professor) {
            $this->message->info("Erro ao gerar PDF, solicitação de certificado inexistente ou recentemente deletadas!")->flash();
            redirect("/app");
        }
//        var_dump($professor);

        $academiaPdf = (new Academias())->find("id = :id", "id={$data['aca_id']}", "id, acaNome, acaEnd, acaCadastro")->fetch();
        if (!$academiaPdf) {
            $this->message->info("Erro ao gerar PDF, solicitação de certificado inexistente ou recentemente deletadas!")->flash();
            redirect("/app");
        }

//        var_dump($academiaPdf);

        $campeonatoPdf = (new Post())->findJoin("id = :id AND category = '2' AND camStatus = '1'", "id={$data['cam_id']}",
            "id, title, subtitle, dtevento,
            cidades.cidNome",
            "
                LEFT JOIN cidades ON cidades.cidId = posts.cidade")->fetch();
        if (!$campeonatoPdf) {
            $this->message->info("Erro ao gerar PDF, campeonato inexistente ou recentemente deletadas!")->flash();
            redirect("/app");
        }

//        var_dump($campeonatoPdf);

        $list_tabela_Ranking = (new Ranking())->findJoin("ranking.user_id = :id AND ranking.aca_id = :aca_id AND ranking.cam_id = :cam_id",
            "id={$data['user_id']}&aca_id={$data['aca_id']}&cam_id={$data['cam_id']}",
            "
                ranking.id, ranking.alu_id, ranking.aca_id, ranking.cam_id, ranking.valor,
                karateca.aluNome, karateca.dtfim, karateca.aluSexo, karateca.status, karateca.faixa_id, karateca.aluTipo, 
                karateca.idKumite, karateca.idKata, karateca.dtNasc,
                faixa.faixaNome AS faixa_name",
            "
                LEFT JOIN karateca ON karateca.id = ranking.alu_id
                LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id")->fetch(true);

        if(!$list_tabela_Ranking){
            $list_tabela_Ranking = null;
        }

        $empresa = (new Empresa())->find()->fetch();

        $mpdf = new \Mpdf\Mpdf([
            'margin_left' => 5,
            'margin_right' => 5,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_header' => 10,
            'margin_footer' => 10
        ]);
        $mesNow = date("m");

//        $mpdf->SetProtection(array('print'));
        $mpdf->SetTitle("{$campeonatoPdf->title}.{$professor->name}.pedido-{$mesNow}");
        $mpdf->SetAuthor("{$professor->name} {$professor->sobrenome}");
        $mpdf->SetWatermarkText("FEKTO");
        $mpdf->showWatermarkText = true;
//        $mpdf->watermark_font = 'DejaVuSansCondensed';
        $mpdf->watermarkTextAlpha = 0.1;
        $mpdf->SetDisplayMode('fullpage');


        require __DIR__ . "/../../themes/app/print/campeonatoProfessor.php";
//        $mpdf->WriteHTML($css, 2);
        $mpdf->WriteHTML(ob_get_clean());


//        $mpdf->Output('RELATÓRIO.pdf', "D");
        $mpdf->Output("{$campeonatoPdf->title}.{$professor->name}.pedido-{$mesNow}.pdf", "D");
//        $mpdf->Output();
    }


    public function categoriasCamPDF(?array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

//        var_dump($data);


        $campeonatoPdf = (new Post())->findJoin("id = :id AND category = '2' AND camStatus = '1'", "id={$data['cam_id']}",
            "id, title, subtitle, dtevento,
            cidades.cidNome",
            "
                LEFT JOIN cidades ON cidades.cidId = posts.cidade")->fetch();
        if (!$campeonatoPdf) {
            $this->message->info("Erro ao gerar PDF, campeonato inexistente ou recentemente deletadas!")->flash();
            redirect("/app");
        }

//        var_dump($campeonatoPdf);

        $getCatego = ($data['cat_id'] < 54 ? 'idKata' : 'idKumite');
        $getNomeCat = ($getCatego == "idKata" ? "Kata" : "Kumite");
//        var_dump($getCatego);

        $list_tabela_Ranking = (new Ranking())->findJoin("ranking.{$getCatego} = :id AND ranking.cam_id = :cam_id",
            "id={$data['cat_id']}&cam_id={$data['cam_id']}",
            "
                ranking.id, ranking.user_id, ranking.alu_id, ranking.aca_id, ranking.cam_id, ranking.valor,
                karateca.aluNome, karateca.dtfim, karateca.aluSexo, karateca.status, karateca.faixa_id, karateca.aluTipo, 
                karateca.idKumite, karateca.idKata, karateca.dtNasc,
                faixa.faixaNome AS faixa_name,
                academia.acaNome,
                usuario.name, usuario.sobrenome,
                categoria.Cod, categoria.Idade, categoria.Categoria, categoria.sexo AS catSexo, categoria.faixa",
            "
                LEFT JOIN karateca ON karateca.id = ranking.alu_id
                LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id
                LEFT JOIN academia ON academia.id = ranking.aca_id
                LEFT JOIN usuario ON usuario.id = ranking.user_id
                LEFT JOIN categoria ON categoria.Cod = ranking.{$getCatego}")->fetch(true);

        if(!$list_tabela_Ranking){
            $list_tabela_Ranking = null;
        }


        $empresa = (new Empresa())->find()->fetch();

        $mpdf = new \Mpdf\Mpdf([
            'margin_left' => 5,
            'margin_right' => 5,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_header' => 10,
            'margin_footer' => 10
        ]);
        $mesNow = date("m");

//        $mpdf->SetProtection(array('print'));
        $mpdf->SetTitle("{$getNomeCat}_Cat_{$data['cat_id']}.{$campeonatoPdf->title}");
        $mpdf->SetAuthor("{$getNomeCat}_Cat_{$data['cat_id']}");
        $mpdf->SetWatermarkText("FEKTO");
        $mpdf->showWatermarkText = true;
//        $mpdf->watermark_font = 'DejaVuSansCondensed';
        $mpdf->watermarkTextAlpha = 0.1;
        $mpdf->SetDisplayMode('fullpage');


        require __DIR__ . "/../../themes/app/print/categorias.php";
//        $mpdf->WriteHTML($css, 2);
        $mpdf->WriteHTML(ob_get_clean());


//        $mpdf->Output('RELATÓRIO.pdf', "D");
//        $mpdf->Output("{$getNomeCat}_Cat_{$data['cat_id']}.{$campeonatoPdf->title}.pdf", "D");
        $mpdf->Output();
    }


    public function certificadoAdd(?array $data): void
    {
//        var_dump($data);

        $user = $data['user_id'];
        $aca = $data['aca_id'];

        if(!empty($data["action"]) && $data["action"] == "cadCertificado") {

            if(empty($data["addKaratecas"])) {
                $this->message->info("Selecione atleta(s) para solitar diplomas de faixa.")->flash();
                echo json_encode(["redirect" => url("/app/certificadoAdd/{$aca}/{$user}")]);
                return;
            }

            foreach ($data["addKaratecas"] as $key => $value) {
                $karatecasCertifi = (new Karatecas())->findJoin("id = :id","id={$value}", "id, certificado_id, aluNome, faixa_id, aluTipo,
                    proxfaixa.faixaId AS proxFaixa, proxfaixa.valorExame AS proximaFaixaValor, proxfaixa.valorExameProj AS proximaFaixaValorProj",
                    "LEFT JOIN faixa AS proxfaixa ON proxfaixa.faixaId = karateca.faixa_id + '1'
                    ")->fetch();

                $certi = (new Certificados())->find("alu_id = :id", "id={$karatecasCertifi->id}", "certiDt")->order("id DESC")->fetch();

                if($certi){
                    $getcertifica = $certi->certiDt;
                }else{
                    $getcertifica = date("Y-m-d H:i:s");
                }

                $getValorProxFaixa = ($karatecasCertifi->aluTipo == 1 ? $karatecasCertifi->proximaFaixaValor : $karatecasCertifi->proximaFaixaValorProj);
                $faixaID = $karatecasCertifi->faixa_id + 1;

                $certifica = (new Certificados());
                $certifica->user_id = $user;
                $certifica->aca_id = $aca;
                $certifica->alu_id = $karatecasCertifi->id;
                $certifica->aluNome = $karatecasCertifi->aluNome;
                $certifica->certiDt = date("Y-m-d H:i:s");
                $certifica->certStatus = 3;
                $certifica->faixaId = $faixaID;
                $certifica->faixaIdAnterio = $karatecasCertifi->faixa_id;
                $certifica->dtUltimoExame = $getcertifica;
                $certifica->valorExame = $getValorProxFaixa;
                $certifica->nome_pro = $data["namePro"];
                $certifica->nome_aca = $data["nomaAca"];
                $certifica->tipo = $karatecasCertifi->aluTipo;

                if(!$certifica->save()){
                    $json["message"] = $certifica->message()->render();
                    echo json_encode($json);
                    return;
                }
            }
            $this->message->success("Diplomas Solicitados com sucesso. Oss")->flash();
            echo json_encode(["redirect" => url("/app/certificadoAdd/{$aca}/{$user}")]);
            return;
        }

        if(!empty($data["action"]) && $data["action"] == "delCertificado"){


            if(empty($data["delKaratecas"])) {
                $this->message->info("Selecione atletas para remover desta solicitação de diplomas.")->flash();
                echo json_encode(["redirect" => url("/app/certificadoAdd/{$aca}/{$user}")]);
                return;
            }
            foreach ($data["delKaratecas"] as $key => $value) {
                $delCertificado = (new Certificados())->findById($value);
                $delCertificado->destroy();
            }
            $this->message->success("Atletas removido da solicitação de diplomas com sussece. Oss")->flash();
            echo json_encode(["redirect" => url("/app/certificadoAdd/{$aca}/{$user}")]);
            return;
        }


        $academia = (new Academias())->findById($data["aca_id"]);

        if(!$academia){
            $this->message->warning("Essa academia não existe, por favor selecione uma academia de sua base de dados!")->flash();
            redirect("/app");
        }

        $karatecas = (new Karatecas())->findJoin("karateca.user_id = :id AND karateca.aca_id = :aca_id AND karateca.del = :del 
                AND karateca.id NOT IN (SELECT alu_id FROM certificado WHERE certificado.alu_id = karateca.id AND certificado.certStatus = '3')
                ",
            "id={$this->user->id}&del=0&aca_id={$academia->id}'",
            "
            karateca.id, karateca.aca_id, karateca.user_id, karateca.aluNome, karateca.aluSexo, karateca.status, karateca.faixa_id, 
            karateca.dtfim, karateca.dtNasc, karateca.aluTipo, 
            faixa.faixaNome AS faixa_name, faixa.valor, faixa.valorProj, faixa.valorExame, faixa.valorExameProj, 
            proxfaixa.faixaId AS proxFaixa, proxfaixa.valorExame AS proximaFaixaValor, proxfaixa.valorExameProj AS proximaFaixaValorProj,
            (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = :id AND karateca.aca_id = :aca_id) AS total_alunos",
            " LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id
                LEFT JOIN faixa AS proxfaixa ON proxfaixa.faixaId = karateca.faixa_id + '1'
                ")->order("status, aluNome, aluTipo")->fetch(true);



        $diplomasEmitir = (new Certificados())->findJoin("certificado.user_id = :id AND certificado.certStatus = '3' AND certificado.aca_id = :aca",
            "id={$user}&aca={$aca}",
            "certificado.id, certificado.alu_id, certificado.aca_id, certificado.valorExame, certificado.certStatus, certificado.faixaId, faixaIdAnterio, certiDt,
                karateca.aluNome, karateca.dtfim, karateca.aluSexo, karateca.status,
                faixa.faixaNome AS faixa_name,
                faixaCert.faixaNome AS faixa_nameProxi, faixaCert.valorExame AS valorExameProxima, faixaCert.valorExameProj AS valorExameProjProxima",
            "
                LEFT JOIN karateca ON karateca.id = certificado.alu_id
                LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id
                LEFT JOIN faixa AS faixaCert ON faixaCert.faixaId = certificado.faixaId
            ")->fetch(true);


        $head = $this->seo->render(
            "Solicitar diplomas de faixa - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/app/certificadoAdd"),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("profeCertificado", [
            "head" => $head,
            "user" => $this->user,
            "academia" => $academia,
            "faixas_list" => $this->getAllFaixa(),
            "karatecas" => $karatecas,
            "diplomas" => $diplomasEmitir
        ]);
    }


    public function certificadoListar(?array $data): void
    {


        $certificadoVerifi = (new Certificados())->findJoin("certificado.user_id = :user_id AND certificado.certStatus = '1'",
            "user_id={$this->user->id}",
            "certificado.*,
            faixaAnterior.faixaNome AS faixa_anterior,
            faixaProxima.faixaNome AS faixa_proxima,
            academia.acaNome",
            "
            INNER JOIN faixa faixaAnterior ON faixaAnterior.faixaId = certificado.faixaIdAnterio
            INNER JOIN faixa faixaProxima ON faixaProxima.faixaId = certificado.faixaId
            LEFT JOIN academia ON academia.id = certificado.aca_id"
        )->order("id DESC")->fetch(true);

        if(empty($certificadoVerifi)){
            $certificadoVerifi = null;
        }

        /*

        ,
            "certificado.*,
            faixaAnterior.faixaNome AS faixa_anterior,
            faixaProxima.faixaNome AS faixa_proxima,
            academia.acaNome,
            usuario.name, usuario.sobrenome",
            "
            INNER JOIN faixa faixaAnterior ON faixaAnterior.faixaId = certificado.faixaIdAnterio
            INNER JOIN faixa faixaProxima ON faixaProxima.faixaId = certificado.faixaId
            LEFT JOIN academia ON academia.id = certificado.aca_id
            "

         * */

//        var_dump($certificadoVerifi);
        $head = $this->seo->render(
            'Financeiro Certificado | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("professor/certificadoListar", [
            "head" => $head,
            "certificados" => $certificadoVerifi
        ]);

    }


    public function karatecaImportPrint(?array $data): void
    {

        $professor = Auth::user();


//        $professor = (new User())->find("id = :id", "id={$data['user_id']}", "id, name, sobrenome, email, end, fone, margem")->fetch();
//        if (!$professor) {
//            $this->message->info("Erro ao gerar PDF, solicitação de certificado inexistente ou recentemente deletadas!")->flash();
//            redirect("/app");
//        }
//
//        $academiaPdf = (new Academias())->find("id = :id", "id={$data['aca_id']}", "id, acaNome, acaEnd, acaCadastro")->fetch();
//        if (!$academiaPdf) {
//            $this->message->info("Erro ao gerar PDF, solicitação de certificado inexistente ou recentemente deletadas!")->flash();
//            redirect("/app");
//        }
//
//        $anuidadeAluno = (new Ativar_Aluno())->findJoin("ativar_aluno.user_id = :id AND ativar_aluno.status = '3' AND ativar_aluno.aca_id = :aca",
//            "id={$data['user_id']}&aca={$data['aca_id']}",
//            "ativar_aluno.*,
//                karateca.aluNome, karateca.dtfim, karateca.aluSexo, karateca.status, karateca.faixa_id, karateca.dtNasc, karateca.dtCadastro,
//                faixa.faixaNome AS faixa_name",
//            "
//                LEFT JOIN karateca ON karateca.id = ativar_aluno.alu_id
//                LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id")->fetch(true);
//
//
//        if (!$anuidadeAluno) {
//            $this->message->info("Erro ao gerar PDF, solicitação de certificado inexistente ou recentemente deletadas!")->flash();
//            redirect("/app");
//        }
//
//        $mpdf = new \Mpdf\Mpdf([
//            'margin_left' => 5,
//            'margin_right' => 5,
//            'margin_top' => 10,
//            'margin_bottom' => 10,
//            'margin_header' => 10,
//            'margin_footer' => 10
//        ]);
//        $mesNow = date("m");
//
////        $mpdf->SetProtection(array('print'));
//        $mpdf->SetTitle("diploma.{$professor->name}.{$academiaPdf->acaNome}.pedido-{$mesNow}");
//        $mpdf->SetAuthor("{$professor->name} {$professor->sobrenome}");
//        $mpdf->SetWatermarkText("FEKTO");
//        $mpdf->showWatermarkText = true;
////        $mpdf->watermark_font = 'DejaVuSansCondensed';
//        $mpdf->watermarkTextAlpha = 0.1;
//        $mpdf->SetDisplayMode('fullpage');
//
//
//        require __DIR__ . "/../../themes/app/print/aluno.php";
////        $mpdf->WriteHTML($css, 2);
//        $mpdf->WriteHTML(ob_get_clean());
//
//
////        $mpdf->Output('RELATÓRIO.pdf', "D");
//        $mpdf->Output("Anuidade_Alunos_prof.{$professor->name}._academia_{$academiaPdf->acaNome}.pedido-{$mesNow}.pdf", "D");
////        $mpdf->Output();

        $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

        $solici = (new Transferencia())->findJoin("transfer_karateca.id = :id AND transfer_karateca.user_id = :user AND transfer_karateca.status = '2'",
            "id={$data["import_id"]}&user={$this->user->id}",
            "transfer_karateca.*,
            karateca.id AS aluno_id, karateca.aluNome, karateca.dtNasc, karateca.dtfim, karateca.aluSexo, karateca.cpf, karateca.celu, karateca.faixa_id,
            faixa.faixaNome,
            academia.acaNome,
            (SELECT COUNT(certificado.id) FROM certificado WHERE certificado.alu_id = karateca.id) AS total_certificado,
            (SELECT COUNT(ranking.id) FROM ranking WHERE ranking.alu_id = karateca.id) AS total_participa_eventos",
            " LEFT JOIN karateca ON karateca.id = transfer_karateca.alu_id
                   LEFT JOIN academia ON academia.id = transfer_karateca.aca_id
                   LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id")->fetch();

        if (!$solici) {
            $this->message->info("Erro ao gerar PDF, solicitação de transferÊncia inexistente!")->flash();
            redirect("/app/importarkarateca");
        }

        $empresa = (new Empresa())->find()->fetch();

        $mpdf = new \Mpdf\Mpdf([
            'margin_left' => 5,
            'margin_right' => 5,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_header' => 10,
            'margin_footer' => 10
        ]);

//        $mpdf->SetProtection(array('print'));
        $mpdf->SetTitle("Ativação_de_Transfer.{$data["import_id"]}");
        $mpdf->SetAuthor("{$this->user->fullName()}");
        $mpdf->SetWatermarkText("FEKTO");
        $mpdf->showWatermarkText = true;
//        $mpdf->watermark_font = 'DejaVuSansCondensed';
        $mpdf->watermarkTextAlpha = 0.1;
        $mpdf->SetDisplayMode('fullpage');


        require __DIR__ . "/../../themes/app/print/tranferKaratecaUnit.php";
//        $mpdf->WriteHTML($css, 2);
        $mpdf->WriteHTML(ob_get_clean());


//        $mpdf->Output('RELATÓRIO.pdf', "D");
        $mpdf->Output("Solicitação de Transferência de ID {$solici->alu_id} Nome {$solici->aluName}.pdf", "D");
//        $mpdf->Output();

    }


    public function signature(?array $data):void
    {
        $head = $this->seo->render(
            "Minha Assinatura - " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url(),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("signature", [
            "head" => $head,
            "subscription" => (new AppSubscription())->find("user_id = :user AND status != :status",
                "user={$this->user->id}&status=canceled")->fetch(),
            "orders" => (new AppOrder())
                ->find("user_id = :user", "user={$this->user->id}")
                ->order("created_at DESC")
                ->fetch(true),
            "plans" => (new AppPlan())->find("status = :status", "status=active")
                ->order("name, price")
                ->fetch(true)

        ]);
    }


    /**********************************************************************************************
    GERENCIAMENTO DE CONTRATO - STATUTO
     *********************************************************************************************/
    public function contract(?array $data):void
    {
        if(!empty($data["aceiteContrato"])) {

            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
            if(User()->id != $data["user_id"]){
                $this->message->error("Erro ao autenticar Usuário, tente novamente, se esse erro for recorrente contate o suporte...")->flash();
                redirect("/app/contrato");
                return;
            }

            $contrato = (new User())->find("id = :id","id={$data["user_id"]}")->fetch();
            if($contrato){
                $dtcontrato =  date("Y-m-d H:i:s");
                $sqlUpdate = "UPDATE usuario SET contrato = 'confirmed', dtContrato = '$dtcontrato'  WHERE id = '$contrato->id'";
                $sqlUpdate = Connect::getInstance()->prepare($sqlUpdate);
                $sqlUpdate->execute();
            }
            $this->message->success("Contrato aceito e assinado digitalmente, Obrigado por fazer parte dessa familia. Oss.")->flash();
            header("Location: " . url("/app/contrato"));
            exit;
        }

        $head = $this->seo->render(
            "Estatudo da FEKTO- " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/app/contrato"),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("contrato", [
            "head" => $head,
            "user" => $this->user
        ]);
    }

    public function estatuto(?array $data):void
    {
//        var_dump($data);
//
//        if(!empty($data["aceiteContrato"])) {
//
//            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
//            if(User()->id != $data["user_id"]){
//                $this->message->error("Erro ao autenticar Usuário, tente novamente, se esse erro for recorrente contate o suporte...")->flash();
//                redirect("/app/contrato");
//                return;
//            }
//
//            $contrato = (new User())->find("id = :id","id={$data["user_id"]}")->fetch();
//            if($contrato){
//                $dtcontrato =  date("Y-m-d H:i:s");
//                $sqlUpdate = "UPDATE usuario SET contrato = 'confirmed', dtContrato = '$dtcontrato'  WHERE id = '$contrato->id'";
//                $sqlUpdate = Connect::getInstance()->prepare($sqlUpdate);
//                $sqlUpdate->execute();
//            }
//            $this->message->success("Contrato aceito e assinado digitalmente, Obrigado por fazer parte dessa familia. Oss.")->flash();
//            header("Location: " . url("/app/contrato"));
//            exit;
//        }
        $head = $this->seo->render(
            "Estatudo da FEKTO- " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            url("/app/estatuto"),
            theme("/assets/images/share.jpg"),
            false
        );

        echo $this->view->render("contrato/estatuto", [
            "head" => $head,
            "user" => $this->user
        ]);
    }


    /**********************************************************************************************
    GERENCIAMENTO DE USUÁRIOS
     *********************************************************************************************/
    public function usuarios(?array $data): void
    {
        if ($this->user->level < 3) {
            $this->message->warning("Desculpe, mas você não tem permissão para acessar este painel de controle.")->flash();
            redirect("/app");
        }
        //SEARCH Redirect
        if(!empty($data["s"])){
            $s = str_search($data["s"]);
            echo json_encode(["redirect" => url("/app/usuarios/{$s}/1")]);
            return;
        }
        $search = null;
        //$usuarios = (new User())->find("level < '4'");
        $usuarios = (new User())->find("level < '4'","", "
         usuario.id, usuario.name, usuario.sobrenome, usuario.email, usuario.level, usuario.photo, usuario.created_at,
         (SELECT COUNT(academia.user_id) FROM academia WHERE academia.user_id = usuario.id) AS total_academias, 
         (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id AND status = '1' AND dtfim >= NOW()) AS alunos_ativos, 
         (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id AND (status = '2' OR dtfim <= NOW())) AS alunos_inativos, 
         (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id) AS total_alunos", "");

        if(!empty($data["search"]) && str_search($data["search"]) != "all"){
            $search = str_search($data["search"]);
//            $usuarios = (new Users())->find ->find("first_name LIKE :s","s=%{$search}%"); //OR last_name LIKE :s OR email LIKE :s
            $usuarios = (new User())->find("MATCH(name, sobrenome, email) AGAINST(:s)", "s={$search}",
                "usuario.id, usuario.name, usuario.sobrenome, usuario.email, usuario.level, usuario.photo, usuario.created_at,
         (SELECT COUNT(academia.user_id) FROM academia WHERE academia.user_id = usuario.id) AS total_academias, 
         (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id AND status = '1' AND dtfim >= NOW()) AS alunos_ativos, 
         (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id AND (status = '2' OR dtfim <= NOW())) AS alunos_inativos, 
         (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id) AS total_alunos", "");
            if(!$usuarios->count()){
                $this->message->info("Sua pesquisa não retornou resultados!")->flash();
                redirect("/app/usuarios");
            }
        }

        $all = ($search ?? "all");
        $pager = new Pager(url("/app/usuarios/{$all}/"));
        $pager->pager($usuarios->count(), 16, (!empty($data["page"]) ? $data["page"] : 1));

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Gerenciar Usuários",
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("users/usuarios", [
            "head" => $head,
            "search" => $search,
            "users" => $usuarios->order("photo DESC, name, id")->limit($pager->limit())->offset($pager->offset())->fetch(true),
            "paginator" => $pager->render()
        ]);
    }

    public function user(?array $data): void
    {
        if(!empty($data["action"]) && $data["action"] == "create") {
            $data["password"] = '12345';
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
            $pass = $data["password"];
            $userCreate = (new User());
            $userCreate->name = $data["name"];
            $userCreate->sobrenome = $data["sobrenome"];
            $userCreate->sexo = $data["sexo"];
            $userCreate->faixa = $data["faixa"];
            $userCreate->nasc = date_fmt_back($data["nasc"]);
//            $userCreate->cpf = str_replace([".", "-"], ["", ""], $data["document"]);
            $userCreate->cpf = preg_replace("/[^0-9]/", "", $data["cpf"]);
            $userCreate->cidade = $data["cidade"];
            $userCreate->email = $data["email"];
            $userCreate->password = $data["password"];
            $userCreate->pass = $pass;
            $userCreate->level = 1;
            $userCreate->margem = 0;
            $userCreate->status = 'created';
            //$userCreate->margem = preg_replace("/[^0-9]/", "", $data["margem"]);

            if(!validaCPF($userCreate->cpf)){
                $json["message"] = $this->message->error("O CPF informado não é inválido \"{$userCreate->cpf}\"!")->render();
                echo json_encode($json);
                return;
            }

            $compareCpfCadastrado = (new User())->find("cpf = :e","e={$userCreate->cpf}")->fetch();

            if (!empty($compareCpfCadastrado)) {
                $json["message"] = $this->message->error("O CPF informado {$userCreate->cpf} já está cadastrado, Solicite troca de senha ou fale conosco!")->render();
                echo json_encode($json);
                return;
            }

            if(!empty($_FILES["photo"])){
                $files = $_FILES["photo"];
                $upload = new Upload();
                $image = $upload->image($files, $userCreate->name);

                if(!$image){
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }
                $userCreate->photo = $image;
            }

            if($userCreate->status != "confirmed") {
                $view = new View(__DIR__ . "/../../shared/views/email");
                $message = $view->render("confirm", [
                    "first_name" => $userCreate->name,
                    "confirm_link" => url("/obrigado/" . base64_encode($userCreate->email))
                ]);

                (new Email())->bootstrap(
                    "Ative sua conta no " . CONF_SITE_NAME,
                    $message,
                    $userCreate->email,
                    "{$userCreate->name} {$userCreate->sobrenome}"
                )->send();
            }

            if(!$userCreate->save()){
                $json["message"] = $userCreate->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Usuário criado com sucesso.")->flash();
            echo json_encode(["redirect" => url("/app/usuarios/{$userCreate->name}/1")]);
            return;
        }

        if(!empty($data["action"]) && $data["action"] == "update") {
//            var_dump($data);
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
            $userUpdate = (new User())->findById($data["user_id"]);

            if(Auth::user()->id != $userUpdate->id && Auth::user()->level < $userUpdate->level){
                $this->message->error("Você não tem permissão para editar este usuário...")->flash();
                echo json_encode(["redirect" => url("/app/usuarios")]);
                return;
            }

            if(!empty($data["password"])) {
                $userUpdate->pass = $data["password"];
                $userUpdate->password = $data["password"];
            }
            $userUpdate->name = $data["name"];
            $userUpdate->sobrenome = $data["sobrenome"];
            $userUpdate->sexo = $data["sexo"];
            $userUpdate->faixa = $data["faixa"];
            $userUpdate->nasc = date_fmt_back($data["nasc"]);
//            $userUpdate->cpf = str_replace([".", "-"], ["", ""], $data["document"]);
            $userUpdate->cpf = preg_replace("/[^0-9]/", "", $data["cpf"]);
//            $userUpdate->email = $data["email"];
            $userUpdate->cidade = $data["cidade"];
            $userUpdate->margem = preg_replace("/[^0-9]/", "", $data["margem"]);
            $userUpdate->celu = str_replace([".", "-"], ["", ""], $data["celu"]);

            if(!validaCPF($userUpdate->cpf)){
                $json["message"] = $this->message->error("O CPF informado não é inválido \"{$userUpdate->cpf}\"!")->render();
                echo json_encode($json);
                return;
            }

//            $compareCpfCadastrado = (new User())->find("cpf = :e","e={$userUpdate->cpf}")->fetch();
            $compareCpfCadastrado = (new User())->find("cpf = :e AND id != :i","e={$userUpdate->cpf}&i={$data['user_id']}")->fetch();

            if (!empty($compareCpfCadastrado)) {
                $json["message"] = $this->message->error("O CPF informado {$userUpdate->cpf} já está cadastrado, Solicite troca de senha ou fale conosco!")->render();
                echo json_encode($json);
                return;
            }

            if(!empty($_FILES["photo"])){

                if($userUpdate->photo && file_exists(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$userUpdate->photo}")){
                    unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$userUpdate->photo}");
                    (new Thumb())->flush($userUpdate->photo);
                }
                $files = $_FILES["photo"];
                $upload = new Upload();
                $image = $upload->image($files, $userUpdate->id.$userUpdate->name);

                if(!$image){
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }
                $userUpdate->photo = $image;
            }

            if(!$userUpdate->save()){
                $json["message"] = $userUpdate->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Usuário atualizado com sucesso...")->flash();
            echo json_encode(["redirect" => url("/app/usuarios/{$userUpdate->name}/1")]);
            return;
        }

        if(!empty($data["action"]) && $data["action"] == "delete") {
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
            $userDelete = (new User())->findById($data["user_id"]);

            if(!$userDelete){
                $this->message->error("Você tentou Excluir um Usuário que não existe ou já foi removido!")->flash();
                echo json_encode(["reload" => true]);
                return;
            }

            if(Auth::user()->id != $userDelete->id && Auth::user()->level < $userDelete->level){
                $this->message->error("Você não tem permissão para editar este usuário...")->flash();
                redirect("/app/usuarios");
                return;
            }

            if(Auth::user()->id == $userDelete->id || Auth::user()->level < $userDelete->level){
                $this->message->error("Você não remover o seu própio usuarios...")->flash();
                redirect("/admin/users/home");
                return;
            }

            $alunosDelete = (new User())->find("id = :id","id={$data["user_id"]}", "
             id,
             (SELECT COUNT(academia.user_id) FROM academia WHERE academia.user_id = usuario.id) AS total_academias,
             (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id) AS total_alunos,
             (SELECT COUNT(certificado.user_id) FROM certificado WHERE certificado.user_id = usuario.id AND certStatus = '3') AS total_certificado, 
             (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id AND status = '1' AND dtfim >= NOW()) AS alunos_ativos", "")->fetch();
            if($alunosDelete->total_certificado > 0) {
                $deleCertificados3 = (new Certificados())->find("user_id = :id AND certStatus = 3", "id={$data["user_id"]}", "id")->fetch(true);
                if ($deleCertificados3) {
                    foreach ($deleCertificados3 as $certDel){
                        $sqlDel = "DELETE FROM certificado WHERE id = '$certDel->id'";
                        $sqlDel = Connect::getInstance()->prepare($sqlDel);
                        $sqlDel->execute();
                    }
                }
            }

            if($alunosDelete->total_alunos > 0) {
                $deleKaratecas3 = (new Karatecas())->find("user_id = :id", "id={$data["user_id"]}", "id")->fetch(true);
                if ($deleKaratecas3) {
                    foreach ($deleKaratecas3 as $karatDel){
                        $sqlDel = "DELETE FROM karateca WHERE id = '$karatDel->id'";
                        $sqlDel = Connect::getInstance()->prepare($sqlDel);
                        $sqlDel->execute();
                    }
                }
            }

            if($alunosDelete->total_academias > 0) {
                $deleAcademias = (new Academias())->find("user_id = :id", "id={$data["user_id"]}")->fetch(true);
                if ($deleAcademias) {
                    foreach ($deleAcademias as $AcaDel){
                        if($AcaDel->photo && file_exists(__DIR__ . "/../../storage/{$AcaDel->photo}")){
                            unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$AcaDel->photo}");
                            (new Thumb())->flush($AcaDel->photo);
                        }
                        $sqlDel = "DELETE FROM academia WHERE id = '$AcaDel->id'";
                        $sqlDel = Connect::getInstance()->prepare($sqlDel);
                        $sqlDel->execute();
                    }
                }
            }

            if($userDelete->photo && file_exists(__DIR__ . "/../../../". CONF_UPLOAD_DIR ."/{$userDelete->photo}")){
                unlink(__DIR__ . "/../../../". CONF_UPLOAD_DIR ."/{$userDelete->photo}");
                (new Thumb())->flush($userDelete->photo);
            }

            $userDelete->destroy();
            $this->message->success("O(a) Usuário(a) {$userDelete->name} {$userDelete->sobrenome} foi removido(a) com sucesso")->flash();
            echo json_encode(["redirect" => url("/app/usuarios")]);
            return;
        }

        $userEdit = null;
        if(!empty($data["user_id"])){
            $userId = filter_var($data["user_id"], FILTER_VALIDATE_INT);
            if(!$userId){
                $this->message->warning("Usuário selecionado não existe ou foi removido!")->flash();
                redirect("/app/usuarios");
                return;
            }

            $userEdit = (new User())->find("id = :id","id={$userId}", "
             *,
             (SELECT COUNT(academia.user_id) FROM academia WHERE academia.user_id = usuario.id) AS total_academias,
             (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id) AS total_alunos, 
             (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id AND status = '1' AND dtfim >= NOW()) AS alunos_ativos", "")->fetch();

            if(!$userEdit){
                $this->message->warning("Usuário selecionado não existe ou foi removido!")->flash();
                redirect("/app/usuarios");
                return;
            }

            if(Auth::user()->id != $userEdit->id && Auth::user()->level < $userEdit->level){
//                var_dump(Auth::user()->level, $userEdit->level);
//                exit();
                $this->message->error("Você não tem permissão para editar este usuário...")->flash();
                redirect("/app/usuarios");
                return;
            }
        }

        if(empty($userEdit)) {
            $photoUser = theme("/assets/images/avatar.jpg", CONF_VIEW_APP);
            $userEdit = null;
        }else{
            $photoUser = ($userEdit->photo() ? image($userEdit->photo, 360, 360) : theme("/assets/images/avatar.jpg", CONF_VIEW_APP));
        }

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Gerenciar ".($userEdit ? $userEdit->fullName() : "Novo Usuário!"),
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );


        echo $this->view->render("users/user", [
            "head" => $head,
            "user" => $userEdit,
            "photo" => $photoUser,
            "getCidades" => $this->getAllCidades(),
            "faixa" => $this->getAllFaixa()
        ]);
    }

    public function todosKaratecas(?array $data): void
    {
        if(!empty($data["action"]) && $data["action"] == "pesquisarAlunos") {
            var_dump($data);
            exit();
        }


        if ($this->user->level < 3) {
            $this->message->warning("Desculpe, mas você não tem permissão para acessar este painel de controle.")->flash();
            redirect("/app");
        }
        //SEARCH Redirect
        if(!empty($data["s"])){



            $s = $data["s"];
            $prof = $data["professor"];

            if(!empty($data["professor"])){
                echo json_encode(["redirect" => url("/app/todosKaratecas/{$s}/{$data["professor"]}/1")]);
                return;
            }else {
                echo json_encode(["redirect" => url("/app/todosKaratecas/{$s}/0/1")]);
                return;
            }
        }

        $search = null;

        if(!empty($data['professor'])){
            $prof = " karateca.user_id = karateca.{$data['professor']}";
        }else{
            $prof = "";
        };

        $getKaratecas = (new Karatecas())->findJoin("{$prof}", "",
            "karateca.id, karateca.aca_id, karateca.user_id, karateca.aluNome, karateca.aluSexo, karateca.status, karateca.dtfim, karateca.dtNasc, karateca.aluTipo, karateca.faixa_id, 
            academia.acaNome, 
            faixa.faixaNome, 
            usuario.name, usuario.sobrenome,
            (SELECT COUNT(certificado.id) FROM certificado WHERE certificado.alu_id = karateca.id) AS total_certificado,
            (SELECT COUNT(ranking.id) FROM ranking WHERE ranking.alu_id = karateca.id) AS total_participa_eventos
            ",
            " 
            INNER JOIN faixa ON faixa.faixaId = karateca.faixa_id
            INNER JOIN academia ON academia.id = karateca.aca_id
            INNER JOIN usuario ON usuario.id = karateca.user_id
            ");

        if(!empty($data["search"]) && str_search($data["search"]) != "all"){
            $search = str_search($data["search"]);
            //findJoin("first_name LIKE :s","s=%{$search}%"); //OR last_name LIKE :s OR email LIKE :s
            //findJoin("MATCH(karateca.aluNome) AGAINST(:s)", "s={$search}",
            $getKaratecas = (new Karatecas())->findJoin("MATCH(karateca.aluNome) AGAINST(:s)", "s={$search}",
                "karateca.id, karateca.aca_id, karateca.user_id, karateca.aluNome, karateca.aluSexo, karateca.status, karateca.dtfim, karateca.dtNasc, karateca.aluTipo, karateca.faixa_id, 
            academia.acaNome, 
            faixa.faixaNome, 
            usuario.name, usuario.sobrenome,
            (SELECT COUNT(certificado.id) FROM certificado WHERE certificado.alu_id = karateca.id) AS total_certificado,
            (SELECT COUNT(ranking.id) FROM ranking WHERE ranking.alu_id = karateca.id) AS total_participa_eventos
            ",
                " 
            INNER JOIN faixa ON faixa.faixaId = karateca.faixa_id
            INNER JOIN academia ON academia.id = karateca.aca_id
            INNER JOIN usuario ON usuario.id = karateca.user_id
            ");

            if(!$getKaratecas->count()){
                $this->message->info("Sua pesquisa não retornou resultados!")->flash();
                redirect("/app/todosKaratecas");
            }
        }


        $all = ($search ?? "all");
        $pager = new Pager(url("/app/todosKaratecas/{$all}/"));
        $pager->pager($getKaratecas->count(), 50, (!empty($data["page"]) ? $data["page"] : 1));

        $usuarios = (new User())->find("level < 3");

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Gerenciar Karatecas",
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("gerenciar/karatecas", [
            "head" => $head,
            "prof" => $prof,
            "search" => $search,
            "karatecas" => $getKaratecas->order("aluNome")->limit($pager->limit())->offset($pager->offset())->fetch(true),
            "users" => $usuarios->order("name")->fetch(true),
            "paginator" => $pager->render()
        ]);
    }

    public function todosKaratecasEdit(?array $data): void
    {
        if ($this->user->level < 3) {
            $this->message->warning("Desculpe, mas você não tem permissão para acessar este painel de controle.")->flash();
            redirect("/app");
        }

        if(!empty($data['alu_id'])) {
            $alunoEditar = (new Karatecas())->findJoin("karateca.id = :id","id={$data['alu_id']}'",
                "
            karateca.id, karateca.aca_id, karateca.user_id, karateca.aluNome, karateca.aluSexo, karateca.status, karateca.dtNasc, karateca.aluPai, karateca.aluMae, karateca.faixa_id,
            karateca.user_id, karateca.idKata, karateca.idKumite,
            faixa.faixaNome",
                " LEFT JOIN faixa ON faixa.faixaId = karateca.faixa_id")->fetch();

            if($alunoEditar->user_id != $data["user_id"] || $alunoEditar->aca_id != $data["aca_id"]){
                $this->message->warning("Erro ao carregar aluno, dados fornecidos não conferem, Cuidado ao aterar dados manualmente, tente novamente, se esse erro for recorrente contate o suporte...")->flash();
                redirect("/app/todosKaratecas");
                return;
            }
        }


        if(!empty($data["action"]) && $data["action"] == "update") {

            $usuario_aluno = $data['user_id'];
            list($d, $m, $y) = explode("/", $data["aluNasc"]);

            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

            $alunoEditar = (new Karatecas())->find("id = :id AND user_id = :user_id AND aca_id = :aca_id",
                "id={$data['alu_id']}&user_id={$usuario_aluno}&aca_id={$data['aca_id']}")->fetch();

            if(!$alunoEditar){
                $this->message->warning("Desculpe Professor, você não não pode cadastrar um aluno(a) sem direciona-lo a uma academia!")->flash();
                echo json_encode(["redirect" => url("/app/todosKaratecasEdit")]);
                return;
            }

            $academiaAluno = (new Academias())->find("id = :id", "id={$data['acaId']}", "acaTipo")->fetch();



            $alunoEditar->user_id = $usuario_aluno;
            $alunoEditar->aca_id = $data["acaId"];
            $alunoEditar->aluTipo = $academiaAluno->acaTipo;
            $alunoEditar->aluNome = $data["aluNome"];
            $alunoEditar->aluMae = $data["aluMae"];
            $alunoEditar->aluPai = $data["aluPai"];
            $alunoEditar->aluSexo = $data["aluSexo"];
            $alunoEditar->dtNasc = "{$y}-{$m}-{$d}";
            $alunoEditar->faixa_id = $data["faixaAtual"];

            if(!empty($data["kata"])){
                $alunoEditar->idKata = $data["kata"];
            }
            if(!empty($data["kumite"])){
                $alunoEditar->idKumite = $data["kumite"];
            }

//            var_dump($alunoEditar);
//            exit();

            if(!$alunoEditar->save()){
                $json["message"] = $alunoEditar->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Karateca atualizado com sucesso...")->flash();
            echo json_encode(["redirect" => url("/app/todosKaratecasEdit/{$data['alu_id']}/{$data['user_id']}/{$data['aca_id']}")]);
            return;
        }

        $academias = (new Academias())->find("user_id = :id","id={$data['user_id']}", "
         academia.id, academia.acaNome, academia.acaTipo")->fetch(true);

        $categorias = (new Categorias())->find()->fetch(true);

        $get_faixa = (new Faixa())->find("faixaId < '17'")->fetch(true);

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Gerenciar Karatecas",
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("gerenciar/todosKaratecasEdit", [
            "head" => $head,
            "data" => $data,
            "academias" => $academias,
            "faixas_list" => $get_faixa,
            "categorias" => $categorias,
            "editarAluno" => $alunoEditar,
            "faixaAtual" => $this->getAllFaixa()
        ]);
    }



    /**********************************************************************************************
    APP LOGOUT
     *********************************************************************************************/
    public function logout()
    {
        (new Message())->info("Você saiu com sucesso " . Auth::user()->name . ". Volte logo :)")->flash();

        Auth::logout();
        redirect("/entrar");
    }


    /**********************************************************************************************
    GERENCIAMENTO DE NOTICIAS
     *********************************************************************************************/
    public function noticias(?array $data): void
    {
        if(!empty($data["s"])){
            $s = str_search($data["s"]);
            echo json_encode(["redirect" => url("/app/noticias/{$s}/1")]);
            return;
        }

        $search = null;
        $posts = (new Post())->find("category = '1'");

        if(!empty($data["search"]) && str_search($data["search"]) != "all"){
            $search = str_search($data["search"]);
//            $posts = (new Post())->find("MATCH(title, subtitle) AGAINST(:s)", "s={$search}");
            $posts = (new Post())->find("category = '1' AND title LIKE :s OR subtitle LIKE :s", "s=%{$search}%");
            if(!$posts->count()){
                $this->message->info("Sua pesquisa não retornou resultados!")->flash();
                redirect("/app/noticias");
            }
        }


        if(Auth::user()->level < 2){
            $this->message->error("Você não tem permissão para editar esta Noticia...")->flash();
            redirect("/app");
            return;
        }

        if(!empty($data['noticia_id'])){
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
            $postDelete = (new Post())->findById($data["noticia_id"]);

            if(!$postDelete){
                $this->message->error("Você tentou Excluir uma notícias que não existe ou já foi removida!")->flash();
                redirect("/app/noticias");
                return;
            }

            if($postDelete->cover && file_exists(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$postDelete->cover}")){
                unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$postDelete->cover}");
                (new Thumb())->flush($postDelete->cover);
            }

            $postDelete->destroy();
            $this->message->success("O Campeonato {$postDelete->title} removido com sucesso")->flash();
            redirect("/app/noticias");
            return;
        }




        $all = ($search ?? "all");
        $pager = new Pager(url("/app/noticias/{$all}/"));
        $pager->pager($posts->count(), 12, (!empty($data["page"]) ? $data["page"] : 1));


        $head = $this->seo->render(
            'Notcias da FEKTO | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );


        echo $this->view->render("noticias/noticias", [
            "head" => $head,
            "posts" => $posts->limit($pager->limit())->offset($pager->offset())->order("post_at DESC")->fetch(true),
            "paginator" => $pager->render(),
            "search" => $search
        ]);
    }



    public function noticia(?array $data): void
    {
        //MCE IMAGE UPLOAD
        if(!empty($data["upload"]) && !empty($_FILES["image"])){
            $file = $_FILES["image"];
            $upload = new Upload();
            $image = $upload->image($file, "post-".time());

            if(!$image){
                $json["message"] = $upload->message()->render();
                echo json_encode($json);
                return;
            }

            $json["mce_image"] = '<img style="width: 100%;" src="'. url("/storage/{$image}") .'" alt="imagem relacionada" title="imagem relacionada">';
            echo json_encode($json);
            return;
        }

        if(!empty($data["action"]) && $data["action"] == "create") {
            $content = $data["content"];
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

            $postCreate = new Post();
            $postCreate->author = user()->id;
            $postCreate->category = 1;
            $postCreate->title = $data["title"];
            $postCreate->uri = str_slug($postCreate->title);
            $postCreate->subtitle = $data["subtitle"];
            $postCreate->content = str_replace(["{title}"], [$postCreate->title], $content);
            $postCreate->status = $data["status"];;
            $postCreate->post_at = date_fmt_back($data["post_at"]);

            if(!empty($_FILES["imagem"])){
                $files = $_FILES["imagem"];
                $upload = new Upload();
                $image = $upload->image($files, $postCreate->title);

                if(!$image){
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }
                $postCreate->cover = $image;
            }

            if(!$postCreate->save()){
                $json["message"] = $postCreate->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Notícia, {$postCreate->title} cadastrada com sucesso.")->flash();
            $json["redirect"] = url("/app/noticia/{$postCreate->id}");
            echo json_encode($json);
            return;
        }

        //POST UPDATE

        if(!empty($data["action"]) && $data["action"] == "update"){
            $content = $data["content"];
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
            $postEdit = (new Post())->findById($data["noticia_id"]);

            if(!$postEdit){
                $this->message->error("Você esta tentando editar uma notícia que não exite ou foi removido!")->flash();
                echo json_encode(["redirect" => url("/noticias")]);
                return;
            }

            $postEdit->title = $data["title"];
//            $postEdit->uri = str_slug($postEdit->title);
            $postEdit->subtitle = $data["subtitle"];
            $postEdit->content = str_replace(["{title}"], [$postEdit->title], $content);
//            $postEdit->video = $data["video"];
            $postEdit->status = $data["status"];
            $postEdit->post_at = date_fmt_back($data["post_at"]);

            if(!empty($_FILES["imagem"])){
                if($postEdit->cover && file_exists(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$postEdit->cover}")){
                    unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$postEdit->cover}");
                    (new Thumb())->flush($postEdit->cover);
                }
                $files = $_FILES["imagem"];
                $upload = new Upload();
                $image = $upload->image($files, $postEdit->title);

                if(!$image){
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }
                $postEdit->cover = $image;
            }

            if(!$postEdit->save()){
                $json["message"] = $postEdit->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Noticia \"{$postEdit->title}\" atualizado com sucesso...")->flash();
            $json["redirect"] = url("/app/noticia/{$postEdit->id}");
            echo json_encode($json);
            return;
        }

        $post = null;

        if(!empty($data["noticia_id"])){
            $postId = filter_var($data["noticia_id"], FILTER_VALIDATE_INT);
            $post = (new Post())->findById($postId);
            if(!$post){
                $this->message->warning("Você tentou editar uma notícias que não existe ou já foi removida!")->flash();
                redirect("/app/noticias");
                return;
            }
        }

        $head = $this->seo->render(
            'Notcias da FEKTO | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("noticias/noticia", [
            "head" => $head,
            "post" => $post,
        ]);
    }

    /**********************************************************************************************
    GERENCIAMENTO DE CAMPEONATOS
     *********************************************************************************************/

    public function galerias(?array $data): void
    {
        if(Auth::user()->level < 3){
            $this->message->error("Você não tem permissão para editar Campeonatos...")->flash();
            redirect("/app");
            return;
        }

        if(!empty($data["del_gelery"])){
            $deletarGaleriaCopleta = (new Galery())->find("id = :id AND id_of IS NULL", "id={$data["del_gelery"]}")->fetch();
            if(!$deletarGaleriaCopleta){
                $this->message->warning("Você tentou excluir uma galeria que não foi encontrada ou foi removida anteriomente!")->flash();
                redirect("/app/galerias");
                return;
            }
            $deletarFotos = (new Galery())->find("id_of = :id_of", "id_of={$deletarGaleriaCopleta->id}")->fetch(true);
            if(!empty($deletarFotos)){
                foreach ($deletarFotos as $delItem){

                    echo $delItem->id." - " . $delItem->cover."<br/>";
                    if($delItem->cover && file_exists(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$delItem->cover}")){
                        unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$delItem->cover}");
                        (new Thumb())->flush($delItem->cover);
                    }
                    $delItem->destroy();
                }
            }

            if($deletarGaleriaCopleta->cover && file_exists(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$deletarGaleriaCopleta->cover}")){
                unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$deletarGaleriaCopleta->cover}");
                (new Thumb())->flush($deletarGaleriaCopleta->cover);
            }

            $deletarGaleriaCopleta->destroy();
            $this->message->success("A Galeria de fotos com ID {$deletarGaleriaCopleta->id} foi removida com sucesso")->flash();
            redirect("/app/galerias");
            return;
        }

//        if(!empty($data['del_id'])){
//            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
//            $postDelete = (new Post())->findById($data["del_id"]);
//
//            if(!$postDelete){
//                $this->message->error("Você tentou Excluir um Campeonato que não existe ou já foi removido!")->flash();
//                redirect("/app/campeonatos");
//                return;
//            }
//
//            if($postDelete->cover && file_exists(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$postDelete->cover}")){
//                unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$postDelete->cover}");
//                (new Thumb())->flush($postDelete->cover);
//            }
//
//            $postDelete->destroy();
//            $this->message->success("O Campeonato {$postDelete->title} removido com sucesso")->flash();
//            redirect("/app/campeonatos");
//            return;
//        }


//        $posts = (new Galery())->find()->fetch(true);
//        if($posts) {
//            foreach ($posts as $item) {
//                $dateAgora = date('Y-m-d',strtotime($item->dtevento.'+2 day'));
//                if (date('Y-m-d',strtotime($item->dtevento.'+2 day')) < $agora) {
//                    $sqlUpdate = "UPDATE posts SET camStatus = '0' WHERE id = '$item->id'";
//                    $sqlUpdate = Connect::getInstance()->prepare($sqlUpdate);
//                    $sqlUpdate->execute();
//                }
//            }
//        }

        $posts = (new Galery())->find("galery.id_of IS NULL", "",
            "galery.*,
            (SELECT COUNT(gal.id) FROM galery as gal WHERE gal.id_of = galery.id) AS qtd_fotos
            ")->order("created_at DESC")->fetch(true);


        $head = $this->seo->render(
            'Galerias de Fotos da FEKTO | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app/galerias",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("galery/galerias", [
            "head" => $head,
            "posts" => $posts
        ]);
    }

    public function galery(?array $data): void
    {
        if(!empty($data["del_foto_id"])){
            $del_galery_foto = (new Galery())->find("id = :id AND id_of = :id_of", "id={$data["del_foto_id"]}&id_of={$data["galery_id"]}")->fetch();

            if($del_galery_foto->cover && file_exists(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$del_galery_foto->cover}")){
                unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$del_galery_foto->cover}");
                (new Thumb())->flush($del_galery_foto->cover);
            }

            $del_galery_foto->destroy();
            $this->message->success("A imagem com Id nº {$del_galery_foto->id} foi removida com sucesso")->flash();
            redirect("/app/galery/{$del_galery_foto->id_of}");
            return;
        }

        if(!empty($data["action"]) && $data["action"] == "create_P") {


            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

            $postCreate = new Galery();
            $postCreate->author = $this->user->id;
            $postCreate->category = $data["referency"];
            $postCreate->title = $data["title"];
            $postCreate->uri = str_slug($postCreate->title);
            $postCreate->subtitle = $data["subtitle"];
            $postCreate->status = $data["status"];;
            $postCreate->created_at = date_fmt_back($data["post_at"]);

//            var_dump($data, $postCreate);
//            exit();

            if(!empty($_FILES["imagem"])){
                $files = $_FILES["imagem"];
                $upload = new Upload();
                $image = $upload->image($files, $postCreate->title);

                if(!$image){
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }
                $postCreate->cover = $image;
            }


            if(!$postCreate->save()){
                $json["message"] = $postCreate->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Galeria, {$postCreate->title} cadastrada com sucesso.")->flash();
            $json["redirect"] = url("/app/galery/{$postCreate->id}");
            echo json_encode($json);
            return;
        }


        if(!empty($data["action"]) && $data["action"] == "update_P"){

            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

            $postUpdate = (new Galery())->findById($data["galery_id"]);
            if(!$postUpdate){
                $this->message->error("Você esta tentando editar uma galeria que não exite ou foi removido!")->flash();
                echo json_encode(["redirect" => url("/galerias")]);
                return;
            }

            $postUpdate->category = $data["referency"];
            $postUpdate->title = $data["title"];
            $postUpdate->uri = str_slug($postUpdate->title);
            $postUpdate->subtitle = $data["subtitle"];
            $postUpdate->status = $data["status"];;
            $postUpdate->created_at = date_fmt_back($data["post_at"]);


            if(!empty($_FILES["image"])){
                if($postUpdate->cover && file_exists(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$postUpdate->cover}")){
                    unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$postUpdate->cover}");
                    (new Thumb())->flush($postUpdate->cover);
                }
                $files = $_FILES["image"];
                $upload = new Upload();
                $image = $upload->image($files, $postUpdate->title, 1200);

                if(!$image){
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }
                $postUpdate->cover = $image;
            }

            if(!$postUpdate->save()){
                $json["message"] = $postUpdate->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Galeria \"{$postUpdate->title}\" atualizada com sucesso...")->flash();
            $json["redirect"] = url("/app/galery/{$postUpdate->id}");
            echo json_encode($json);
            return;
        }


        /*CARREGAMENTO DE VÁRIAS IMAGENS VIA FILE*/
        if(!empty($data["action"]) && $data["action"] == "update_of") {

            if (!empty($_FILES["images"])) {
                $img = $_FILES["images"];

                for ($i = 0; $i < count($img["type"]); $i++) {
                    foreach (array_keys($img) as $keys) {
                        $imagesFile[$i][$keys] = $img[$keys][$i];
                    }
                }

                foreach ($imagesFile as $file) {
                    $upload = new Upload();
                    $image = $upload->image($file, "galeria" . md5($i . rand(1, 999)), 900);

                    if (!$image) {
                        $json["message"] = $upload->message()->render();
                        echo json_encode($json);
                        return;
                    }

                    $cadGalery = (new Galery());
                    $cadGalery->id_of = $data["id_of"];
                    $cadGalery->author = $this->user->id;
                    $cadGalery->cover = $image;

                    if (!$cadGalery->save()) {
                        $json["message"] = $cadGalery->message()->render();
                        echo json_encode($json);
                        return;
                    }
                }
            }
            $this->message->success("Fotos carregadas com sucesso.")->render();
            $json["redirect"] = url("/app/galery/{$data["id_of"]}");
            echo json_encode($json);
            return;
        }

        //POST UPDATE

        if(!empty($data["action"]) && $data["action"] == "update"){
//            $content = $data["content"];
//            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
//            $postEdit = (new Post())->findById($data["noticia_id"]);
//
//            if(!$postEdit){
//                $this->message->error("Você esta tentando editar uma notícia que não exite ou foi removido!")->flash();
//                echo json_encode(["redirect" => url("/noticias")]);
//                return;
//            }
//
//            $postEdit->title = $data["title"];
////            $postEdit->uri = str_slug($postEdit->title);
//            $postEdit->subtitle = $data["subtitle"];
//            $postEdit->content = str_replace(["{title}"], [$postEdit->title], $content);
////            $postEdit->video = $data["video"];
//            $postEdit->status = $data["status"];
//            $postEdit->post_at = date_fmt_back($data["post_at"]);
//
//            if(!empty($_FILES["imagem"])){
//                if($postEdit->cover && file_exists(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$postEdit->cover}")){
//                    unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$postEdit->cover}");
//                    (new Thumb())->flush($postEdit->cover);
//                }
//                $files = $_FILES["imagem"];
//                $upload = new Upload();
//                $image = $upload->image($files, $postEdit->title);
//
//                if(!$image){
//                    $json["message"] = $upload->message()->render();
//                    echo json_encode($json);
//                    return;
//                }
//                $postEdit->cover = $image;
//            }
//
//            if(!$postEdit->save()){
//                $json["message"] = $postEdit->message()->render();
//                echo json_encode($json);
//                return;
//            }
//
//            $this->message->success("Noticia \"{$postEdit->title}\" atualizado com sucesso...")->flash();
//            $json["redirect"] = url("/app/noticia/{$postEdit->id}");
//            echo json_encode($json);
//            return;
        }

        $post = null;
        $galeryFotos = null;

        if(!empty($data["galery_id"])){
            $postId = filter_var($data["galery_id"], FILTER_VALIDATE_INT);
            $post = (new Galery())->findById($postId);
            if(!$post){
                $this->message->warning("Você tentou editar uma galeria que não existe ou já foi removida!")->flash();
                redirect("/app/galerias");
                return;
            }

            $fotos = (new Galery())->find("id_of = :id_of", "id_of={$data["galery_id"]}")->fetch(true);
            if(!empty($fotos)){
                $galeryFotos = $fotos;
            }
        }

        $head = $this->seo->render(
            'Notcias da FEKTO | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("galery/galery", [
            "head" => $head,
            "post" => $post,
            "galeryFotos" => $galeryFotos
        ]);
    }


    /**********************************************************************************************
    GERENCIAMENTO DE CAMPEONATOS
     *********************************************************************************************/

    public function campeonatos(?array $data): void
    {
        if(Auth::user()->level < 3){
            $this->message->error("Você não tem permissão para editar Campeonatos...")->flash();
            redirect("/app");
            return;
        }

        if(!empty($data['del_id'])){
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
            $postDelete = (new Post())->findById($data["del_id"]);

            if(!$postDelete){
                $this->message->error("Você tentou Excluir um Campeonato que não existe ou já foi removido!")->flash();
                redirect("/app/campeonatos");
                return;
            }

            if($postDelete->cover && file_exists(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$postDelete->cover}")){
                unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$postDelete->cover}");
                (new Thumb())->flush($postDelete->cover);
            }

            $postDelete->destroy();
            $this->message->success("O Campeonato {$postDelete->title} removido com sucesso")->flash();
            redirect("/app/campeonatos");
            return;
        }

        $agora = date("Y-m-d");

        $posts = (new Post())->find("category = '2' AND dtevento < NOW() AND camStatus = '1'")->fetch(true);
        if($posts) {
            foreach ($posts as $item) {
                $dateAgora = date('Y-m-d',strtotime($item->dtevento.'+2 day'));
                if (date('Y-m-d',strtotime($item->dtevento.'+2 day')) < $agora) {
                    $sqlUpdate = "UPDATE posts SET camStatus = '0' WHERE id = '$item->id'";
                    $sqlUpdate = Connect::getInstance()->prepare($sqlUpdate);
                    $sqlUpdate->execute();
                }
            }
        }

        $posts = (new Post())->find("category = '2'")->order("camStatus DESC, dtevento ASC")->fetch(true);


        $head = $this->seo->render(
            'Notcias da FEKTO | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("campeonatos/campeonatos", [
            "head" => $head,
            "posts" => $posts
        ]);
    }


    public function campeonato(?array $data): void
    {
//        var_dump($data);
        if(!empty($data["action"]) && $data["action"] == "create") {
            $content = $data["content"];
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

            $postCreate = new Post();
            $postCreate->author = user()->id;
            $postCreate->category = 2;
            $postCreate->title = $data["title"];
            $postCreate->uri = str_slug($postCreate->title);
            $postCreate->subtitle = $data["subtitle"];
            $postCreate->content = str_replace(["{title}"], [$postCreate->title], $content);
            $postCreate->status = 'camp';
            $postCreate->camStatus = $data["camStatus"];
            $postCreate->post_at = date_fmt_back($data["post_at"]);
            $postCreate->dtevento = date_fmt_back($data["dtevento"]);
            $postCreate->prazoInscre = date_fmt_back($data["prazoInscre"]);

            $postCreate->cidade = $data["acaCidade"];
            $postCreate->valor = str_replace([".", ","], ["", "."], $data["valor"]);
            $postCreate->valorProjeto = str_replace([".", ","], ["", "."], $data["valorProjeto"]);

            if(!empty($_FILES["imagem"])){
                $files = $_FILES["imagem"];
                $upload = new Upload();
                $image = $upload->image($files, $postCreate->title);

                if(!$image){
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }
                $postCreate->cover = $image;
            }

            if(!$postCreate->save()){
                $json["message"] = $postCreate->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Evento, {$postCreate->title} criado com sucesso.")->flash();
            $json["redirect"] = url("/app/campeonato/{$postCreate->id}");
            echo json_encode($json);
            return;
        }

        //POST UPDATE
        if(!empty($data["action"]) && $data["action"] == "update"){
            $content = $data["content"];
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
            $postEdit = (new Post())->findById($data["cam_id"]);

            if(!$postEdit){
                $this->message->error("Você esta tentando editar um Campeonato que não exite ou foi removido!")->flash();
                echo json_encode(["redirect" => url("/campeonatos")]);
                return;
            }

            $postEdit->title = $data["title"];
//            $postEdit->uri = str_slug($postEdit->title);
            $postEdit->subtitle = $data["subtitle"];
            $postEdit->content = str_replace(["{title}"], [$postEdit->title], $content);
//            $postEdit->video = $data["video"];
            $postEdit->post_at = date_fmt_back($data["post_at"]);
            $postEdit->camStatus = $data["camStatus"];
            $postEdit->post_at = date_fmt_back($data["post_at"]);
            $postEdit->dtevento = date_fmt_back($data["dtevento"]);
            $postEdit->prazoInscre = date_fmt_back($data["prazoInscre"]);
            $postEdit->cidade = $data["acaCidade"];
            $postEdit->valor = str_replace([".", ","], ["", "."], $data["valor"]);
            $postEdit->valorProjeto = str_replace([".", ","], ["", "."], $data["valorProjeto"]);


            if(!empty($_FILES["imagem"])){
                if($postEdit->cover && file_exists(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$postEdit->cover}")){
                    unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$postEdit->cover}");
                    (new Thumb())->flush($postEdit->cover);
                }
                $files = $_FILES["imagem"];
                $upload = new Upload();
                $image = $upload->image($files, $postEdit->title);

                if(!$image){
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }
                $postEdit->cover = $image;
            }

            if(!$postEdit->save()){
                $json["message"] = $postEdit->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Evento \"{$postEdit->title}\" atualizado com sucesso...")->flash();
            $json["redirect"] = url("/app/campeonato/{$postEdit->id}");
            echo json_encode(["reload" => true]);
            return;
        }

        //POST DELETE
        if(!empty($data["action"]) && $data["action"] == "delete"){
            $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);
            $postDelete = (new Post())->findById($data["cam_id"]);

            if(!$postDelete){
                $this->message->error("Você tentou Excluir um POST que não existe ou já foi removido!")->flash();
                echo json_encode(["reload" => true]);
                return;
            }

            if($postDelete->cover && file_exists(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$postDelete->cover}")){
                unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$postDelete->cover}");
                (new Thumb())->flush($postDelete->cover);
            }

            $postDelete->destroy();
            $this->message->success("O Post removido com sucesso")->flash();
            echo json_encode(["reload" => true]);
            return;
        }

        $post = null;

        if(!empty($data["cam_id"])){
            $postId = filter_var($data["cam_id"], FILTER_VALIDATE_INT);
            $post = (new Post())->findById($postId);
        }

        $head = $this->seo->render(
            'Notcias da FEKTO | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("campeonatos/campeonato", [
            "head" => $head,
            "post" => $post,
            "getCidades" => $this->getAllCidades()
        ]);
    }

    public function campeonatoCategorias(?array $data): void
    {
//        var_dump($data);

//        if(Auth::user()->level < 3){
//            $this->message->error("Você não tem permissão para analizar categorias deste evento...")->flash();
//            redirect("/app");
//            return;
//        }

        $postId = filter_var($data["cam_id"], FILTER_VALIDATE_INT);
        $post = (new Post())->findById($postId);
        if(!$post){
            $this->message->error("Evento não encontrado ou removido recentemente...")->flash();
            redirect("/app");
            return;
        }
//AND idKata != '' GROUP BY idKata ORDER BY idKata ASC
        $categoriaKata = (new Ranking())->findJoin("cam_id = :cam_id AND idKata != '' GROUP BY idKata ORDER BY idKata ASC","cam_id={$postId}",
            "id, idKata,
        categoria.*,
        (SELECT COUNT(ranking.cam_id) FROM ranking WHERE ranking.cam_id = :cam_id AND ranking.idKata = categoria.Cod) AS alunos_nesta",
            "
                LEFT JOIN categoria ON categoria.Cod = ranking.idKata
            ")->fetch(true);

        $categoriaKumite = (new Ranking())->findJoin("cam_id = :cam_id AND idKumite != '' GROUP BY idKumite ORDER BY idKumite ASC","cam_id={$postId}",
            "id, idKumite,
        categoria.*,
        (SELECT COUNT(ranking.cam_id) FROM ranking WHERE ranking.cam_id = :cam_id AND ranking.idKumite = categoria.Cod) AS alunos_nesta",
            "
                LEFT JOIN categoria ON categoria.Cod = ranking.idKumite
            ")->fetch(true);

//        var_dump($categoriaKumite, $categoriaKata);




        $head = $this->seo->render(
            "Gerenciamento de Categorias do {$post->title} da FEKTO | " . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("campeonatos/categorias", [
            "head" => $head,
            "post" => $post,
            "categoriaKata" => $categoriaKata,
            "categoriaKumite" => $categoriaKumite
        ]);
    }

    /**********************************************************************************************
    GERENCIAMENTO DO FINANCEIRO PRESIDENTE
     *********************************************************************************************/

    public function financeiro(?array $data): void
    {
        $arrayValorMes = [];
        if(!empty($data['ano'])){
            $getAno = $data['ano'];
        }else{
            $getAno = date('Y');
        }


        if(!empty($data['mes'])){
            $getMes = $data['mes'];
        }else{
            $getMes = date('m');
        }

        $getMesbanco = (new Financeiro())->find("1 = '1' GROUP BY month(criado)")->order("month(criado) DESC")->fetch(true);
        $getAnobanco = (new Financeiro())->find("1 = '1' GROUP BY year(criado)")->order("year(criado) DESC")->fetch(true);
        if(!$getMesbanco){
            $getMesbanco = null;
        }
        $getTabcat = (new Cat_Pagamentos())->find()->fetch(true);

        foreach ($getTabcat as $cate) {
            $getReferenciaFinaceiro = (new Financeiro())->findJoin("referencia = :referencia AND year(criado) = :ano AND month(criado) = :mes",
                ":referencia={$cate->id}&ano={$getAno}&mes={$getMes}",
                "
            financeiro.referencia, financeiro.criado, month(criado) AS mes, year(criado) AS ano, 
            cat_pagamentos.name,
           
            (SELECT COUNT(valorSistema) FROM financeiro WHERE financeiro.referencia = :referencia AND year(criado) = :ano AND month(criado) = :mes AND financeiro.tipo = '1') AS quantidade,
            (SELECT SUM(valorSistema) FROM financeiro WHERE financeiro.referencia = :referencia AND year(criado) = :ano AND month(criado) = :mes AND financeiro.tipo = '1') AS valor_sistema,
            (SELECT SUM(valorFekto) FROM financeiro WHERE financeiro.referencia = :referencia AND year(criado) = :ano AND month(criado) = :mes AND financeiro.tipo = '1') AS valor_fekto,
            (SELECT SUM(valor) FROM financeiro WHERE financeiro.referencia = :referencia AND year(criado) = :ano AND month(criado) = :mes AND financeiro.tipo = '1') AS valor_Total",
                "
            INNER JOIN cat_pagamentos ON cat_pagamentos.id = financeiro.referencia
            ")->fetch();

            if($getReferenciaFinaceiro) {
                $arrayValorMes[] = $getReferenciaFinaceiro;
            }
        }

        if(empty($arrayValorMes)){
            $arrayValorMes = 0;
        }

        $head = $this->seo->render(
            'Financeiro FEKTO | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("financeiro/financ", [
            "head" => $head,
            "resumoMes" => $arrayValorMes,
            "mes" => $getMes,
            "ano" => $getAno,
            "getMesbanco" => $getMesbanco,
            "getAnobanco" => $getAnobanco
        ]);

    }

    public function financPro(?array $data): void
    {
        if ($this->user->level < 3) {
            $this->message->warning("Desculpe, mas você não tem permissão para acessar este painel de controle.")->flash();
            redirect("/app");
        }

        if(!empty($data["user_id"])) {

            $get_professor_ativar = (new User())->find("id = :id AND level = '1' AND (status = '2'  OR dtfim < NOW())", "id={$data['user_id']}")->fetch();
            if(!$get_professor_ativar){
                $this->message->warning("Desculpe, mas você esta tentando ativar um Professor que não existe ou foi removido recentemente.")->flash();
                redirect("/app/financProfessor");
            }

            $get_professor_ativar->status = 1;
            $get_professor_ativar->dtfim = date("Y-01-01 00:00:01",strtotime("+1 year"));

            if(!$get_professor_ativar->save()){
                $json["message"] = $get_professor_ativar->message()->render();
                echo json_encode($json);
                return;
            }

            $get_valor_ativar = (new Faixa())->find("faixaId = '100'")->fetch();
            if($get_valor_ativar->valor < '250'){
                $valorSistema = '30';
            }else{
                $valorSistema = $get_valor_ativar->valor * 15/100;
            }

            $cadFinanceiro = (new Financeiro());
            $cadFinanceiro->referencia = '1';
            $cadFinanceiro->nome_professor = $get_professor_ativar->name .  $get_professor_ativar->sobrenome;
            $cadFinanceiro->user_id = $get_professor_ativar->id;
            $cadFinanceiro->valor = $get_valor_ativar->valor;
            $cadFinanceiro->valorFekto = $get_valor_ativar->valor - $valorSistema;
            $cadFinanceiro->valorSistema = $valorSistema;
            $cadFinanceiro->status = '0';
            $cadFinanceiro->tipo = 1;
            $cadFinanceiro->criado = date("Y-m-d H:i:s");

//            var_dump($get_professor_ativar, $get_valor_ativar, $cadFinanceiro);

            if(!$cadFinanceiro->save()){
                $json["message"] = $cadFinanceiro->message()->render();
                echo json_encode($json);
                return;
            }

//            $id_ativa = $data['aluno_id'];
//            $get_confirma  = Auth::user()->id;
//
//            $sqlUpdate = "UPDATE ativar_aluno SET status = '1', confirPor = '$get_confirma', dataConfi = NOW()  WHERE id = '$id_ativa'";
//            $sqlUpdate = Connect::getInstance()->prepare($sqlUpdate);
//            $sqlUpdate->execute();

            $this->message->success("Professor ativado e com anuidade atualizada com sucesso. Oss.")->flash();
            redirect("/app/financProfessor");
            return;
        }

        $usuarios = (new User())->findJoin("level = '1' AND (status = '2'  OR dtfim < NOW())","", "
         usuario.id, usuario.name, usuario.sobrenome, usuario.email, usuario.level, usuario.photo, usuario.created_at,
         usuario.faixa, usuario.sexo, usuario.dtfim, usuario.status,
         (SELECT COUNT(academia.user_id) FROM academia WHERE academia.user_id = usuario.id) AS total_academias,
         (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id AND status = '1' AND dtfim >= NOW()) AS alunos_ativos,
         (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id AND (status = '2' OR dtfim <= NOW())) AS alunos_inativos,
         (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id) AS total_alunos,
         faixa.valor AS valor_anuidade_professor",
            "LEFT JOIN faixa ON faixa.faixaId = '100'")
            ->order("total_alunos DESC, name")->fetch(true);

        $head = $this->seo->render(
            'Financeiro PROFESSOR FEKTO | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("financeiro/financeiroProfessor", [
            "head" => $head,
            "users" => $usuarios,
        ]);
    }


    public function financProDel(?array $data): void
    {
        if ($this->user->level < 3) {
            $this->message->warning("Desculpe, mas você não tem permissão para acessar este painel de controle.")->flash();
            redirect("/app");
        }

        $get_professor_del = (new User())->find("id = :id","id={$data["user_id"]}", "
             id, photo,
             (SELECT COUNT(academia.user_id) FROM academia WHERE academia.user_id = usuario.id) AS total_academias,
             (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id) AS total_alunos,
             (SELECT COUNT(certificado.user_id) FROM certificado WHERE certificado.user_id = usuario.id AND certStatus = '3') AS total_certificado, 
             (SELECT COUNT(ativar_aluno.user_id) FROM ativar_aluno WHERE ativar_aluno.user_id = usuario.id AND ativar_aluno.status = '3') AS total_anuidades, 
             (SELECT COUNT(karateca.user_id) FROM karateca WHERE karateca.user_id = usuario.id AND status = '1' AND dtfim >= NOW()) AS alunos_ativos", "")->fetch();

        if(!$get_professor_del){
            $this->message->warning("Usuário não exite ou dados informados de forma incorreta!")->flash();
            redirect("/app/financProfessor");
            return;
        }

        if(Auth::user()->id == $get_professor_del->id){
            $this->message->error("Você não pode remover seu próprio Usuário...")->flash();
            redirect("/app/financProfessor");
            return;
        }
        if($get_professor_del->alunos_ativos > 0){
            $this->message->info("Você não pode remover um professor que tenha alunos com anuidade em dias com a FEKTO!")->flash();
            redirect("/app/financProfessor");
            return;
        }

// REMOVER CERTIFICADOS SOLICITADOS COM STATUS AGUARDANDO
        if($get_professor_del->total_anuidades > 0) {
            $deleAnuidades = (new Ativar_Aluno())->find("user_id = :id AND status = 3", "id={$data["user_id"]}", "id")->fetch(true);
            if ($deleAnuidades) {
                foreach ($deleAnuidades as $anuiDel){
                    $sqlDel = "DELETE FROM ativar_aluno WHERE id = '$anuiDel->id'";
                    $sqlDel = Connect::getInstance()->prepare($sqlDel);
                    $sqlDel->execute();
                }
            }
        }

//REMOVER TODOS OS CERTIFICADOS COM STATUS AGUARDANDO
        if($get_professor_del->total_certificado > 0) {
            $deleCertificados3 = (new Certificados())->find("user_id = :id AND certStatus = 3", "id={$data["user_id"]}", "id")->fetch(true);
            if ($deleCertificados3) {
                foreach ($deleCertificados3 as $certDel){
                    $sqlDel = "DELETE FROM certificado WHERE id = '$certDel->id'";
                    $sqlDel = Connect::getInstance()->prepare($sqlDel);
                    $sqlDel->execute();
                }
            }
        }

//REMOVER TODOS OS ALUNOS DESTE USUÁRIO
        if($get_professor_del->total_alunos > 0) {
            $deleKaratecas3 = (new Karatecas())->find("user_id = :id", "id={$data["user_id"]}", "id")->fetch(true);
            if ($deleKaratecas3) {
                foreach ($deleKaratecas3 as $karatDel){
                    $sqlDel = "DELETE FROM karateca WHERE id = '$karatDel->id'";
                    $sqlDel = Connect::getInstance()->prepare($sqlDel);
                    $sqlDel->execute();
                }
            }
        }

        //REMOVER TODAS AS ACADEMIAS E FOTOS CADASTRADAS DESTE USUÁRIO
        if($get_professor_del->total_academias > 0) {
            $deleAcademias = (new Academias())->find("user_id = :id", "id={$data["user_id"]}", "id, photo")->fetch(true);
            if ($deleAcademias) {
                foreach ($deleAcademias as $AcaDel){
                    if($AcaDel->photo && file_exists(__DIR__ . "/../../storage/{$AcaDel->photo}")){
                        unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$AcaDel->photo}");
                        (new Thumb())->flush($AcaDel->photo);
                    }
                    $sqlDel = "DELETE FROM academia WHERE id = '$AcaDel->id'";
                    $sqlDel = Connect::getInstance()->prepare($sqlDel);
                    $sqlDel->execute();
                }
            }
        }

        //REMOVER FOTOS DESTE USUÁRIO
        if($get_professor_del->photo && file_exists(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$get_professor_del->photo}")){
            unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$get_professor_del->photo}");
            (new Thumb())->flush($get_professor_del->photo);
        }

        //POR FIM REMOVER USUÁRIO
        $get_professor_del->destroy();
        $this->message->success("O(a) Usuário(a) {$get_professor_del->name} {$get_professor_del->sobrenome} foi removido(a) com sucesso")->flash();
        redirect("/app/financProfessor");
        return;
    }






    public function financAnuiKaratecas(?array $data): void
    {
        if ($this->user->level < 3) {
            $this->message->warning("Desculpe, mas você não tem permissão para acessar este painel de controle.")->flash();
            redirect("/app");
        }

        $pegar_AtivarALunos = (new Ativar_Aluno())->findJoin("ativar_aluno.status = '3' GROUP BY ativar_aluno.aca_id ASC", "",
            "ativar_aluno.*,
            academia.id, academia.acaNome,
            usuario.name, usuario.sobrenome,
            (SELECT COUNT(ativar_aluno.aca_id) FROM ativar_aluno WHERE ativar_aluno.aca_id = academia.id AND ativar_aluno.status = '3') AS total_alunos",
            "
            LEFT JOIN academia ON academia.id = ativar_aluno.aca_id
            LEFT JOIN usuario ON usuario.id = ativar_aluno.user_id
            ")->fetch(true);

        if(!$pegar_AtivarALunos){
            $pegar_AtivarALunos = null;
        }

        $head = $this->seo->render(
            'Financeiro PROFESSOR FEKTO | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("financeiro/financeiroAnuiKaratecas", [
            "head" => $head,
            "getAcademias" => $pegar_AtivarALunos
        ]);
    }

    public function financAnuiKarateca(?array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

        if ($this->user->level < 3) {
            $this->message->warning("Desculpe, mas você não tem permissão para acessar este painel de controle.")->flash();
            redirect("/app");
        }

        if(!empty($data["aluno_id"])) {

            $get_aluno_ativar = (new Ativar_Aluno())->findById($data['aluno_id']);
            if(!$get_aluno_ativar){
                $this->message->warning("Desculpe, mas você esta tentando ativar um Aluno que não teve solicitação ou foi esta foi removida.")->flash();
                redirect("/app/financAnuidadesKarateca/{$data['user_id']}/{$data['aca_id']}");
            }

            $atuAtualizar = (new Karatecas())->findById($get_aluno_ativar->alu_id);
            if(!$atuAtualizar){
                $this->message->warning("Desculpe, mas você esta tentando atualizar um aluno que não existe ou foi Removido.")->flash();
                redirect("/app/financAnuidadesKarateca/{$data['user_id']}/{$data['aca_id']}");
            }

            $atuAtualizar->status = 1;
            $atuAtualizar->dtfim = date("Y-m-d H:i:s",strtotime("+1 year"));

            if(!$atuAtualizar->save()){
                $json["message"] = $atuAtualizar->message()->render();
                echo json_encode($json);
                return;
            }


            $nomeAcademia = (new Academias())->find("id = :id", "id={$data['aca_id']}")->fetch();
            if($nomeAcademia){
                $nomeAca = $nomeAcademia->acaNome;
            }

            $nomeProfessor = (new User())->find("id = :id", "id={$data['user_id']}")->fetch();
            if($nomeProfessor){
                $nomePro = $nomeProfessor->fullName();
            }

            if($get_aluno_ativar->valor < '40'){
                $valorSistema = '4';
            }else{
                $valorSistema = $get_aluno_ativar->valor * 10/100;
            }

            $cadFinanceiro = (new Financeiro());
            $cadFinanceiro->referencia = '3';
            $cadFinanceiro->nome_aluno = $atuAtualizar->aluNome;
            $cadFinanceiro->nome_academia = $nomeAca;
            $cadFinanceiro->nome_professor = $nomePro;
            $cadFinanceiro->aca_id = $get_aluno_ativar->aca_id;
            $cadFinanceiro->alu_id = $get_aluno_ativar->alu_id;
            $cadFinanceiro->user_id = $get_aluno_ativar->user_id;
            $cadFinanceiro->valor = $get_aluno_ativar->valor;
            $cadFinanceiro->valorFekto = $get_aluno_ativar->valor - $valorSistema;
            $cadFinanceiro->valorSistema = $valorSistema;
            $cadFinanceiro->status = '0';
            $cadFinanceiro->tipo = 1;
            $cadFinanceiro->criado = date("Y-m-d H:i:s");

            if(!$cadFinanceiro->save()){
                $json["message"] = $cadFinanceiro->message()->render();
                echo json_encode($json);
                return;
            }

            $id_ativa = $data['aluno_id'];
            $get_confirma  = Auth::user()->id;

            $sqlUpdate = "UPDATE ativar_aluno SET status = '1', confirPor = '$get_confirma', dataConfi = NOW()  WHERE id = '$id_ativa'";
            $sqlUpdate = Connect::getInstance()->prepare($sqlUpdate);
            $sqlUpdate->execute();

            $this->message->success("Anuidade Atualizada com sucesso. Oss.")->flash();
            redirect("/app/financAnuidadesKarateca/{$data['user_id']}/{$data['aca_id']}");
            return;
        }

        $get_ativar_Alunos = (new Ativar_Aluno())->findJoin("ativar_aluno.user_id = :user_id AND ativar_aluno.aca_id = :aca_id AND ativar_aluno.status = '3'",
            "user_id={$data['user_id']}&aca_id={$data['aca_id']}",
            "ativar_aluno.*,
            usuario.name, usuario.sobrenome,
            academia.acaNome,
            karateca.aluNome, aluSexo, dtNasc,
            faixa.faixaNome AS faixa_anterior,
            (SELECT COUNT(certificado.aca_id) FROM certificado WHERE certificado.aca_id = :aca_id AND certStatus = '3') AS total_certificados",
            "
            INNER JOIN usuario ON usuario.id = ativar_aluno.user_id
            INNER JOIN academia ON academia.id = ativar_aluno.aca_id
            INNER JOIN karateca ON karateca.id = ativar_aluno.alu_id
            INNER JOIN faixa ON faixa.faixaId = ativar_aluno.faixaAtual
            
            ")->fetch(true);
        if(!$get_ativar_Alunos){
            $get_ativar_Alunos = null;
        }

        $head = $this->seo->render(
            'Financeiro Certificado | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("financeiro/financeiroAnuiKarateca", [
            "head" => $head,
            "get_ativar" => $get_ativar_Alunos
        ]);
    }

    //PEGAR SOLICITAÇÕES DE CERTIFICADOS PARA ATIVAR
    public function financCert(?array $data): void
    {
        if ($this->user->level < 3) {
            $this->message->warning("Desculpe, mas você não tem permissão para acessar este painel de controle.")->flash();
            redirect("/app");
        }

        $certificadoVerifi = (new Certificados())->findJoin("certStatus = '3' GROUP BY aca_id ASC", "",
            "certificado.*,
            academia.id, academia.acaNome AS nomeAcademia,
            usuario.name, usuario.sobrenome,
            (SELECT COUNT(certificado.aca_id) FROM certificado WHERE certificado.aca_id = academia.id AND certStatus = '3') AS total_certificados",
            "
            LEFT JOIN academia ON academia.id = certificado.aca_id
            LEFT JOIN usuario ON usuario.id = certificado.user_id
            ")->fetch(true);
        if(!$certificadoVerifi){
            $certificadoVerifi = null;
        }

        $head = $this->seo->render(
            'Financeiro PROFESSOR FEKTO | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("financeiro/financeiroCertificados", [
            "head" => $head,
            "certificados" => $certificadoVerifi
        ]);
    }


    public function financCertGerir(?array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

        if ($this->user->level < 3) {
            $this->message->warning("Desculpe, mas você não tem permissão para acessar este painel de controle.")->flash();
            redirect("/app");
        }

        if(!empty($data["cert_id"])) {
            $certificadoAtivar = (new Certificados())->findById($data['cert_id']);
            if(!$certificadoAtivar){
                $this->message->warning("Desculpe, mas você esta tentando ativar um Diploma que não existe ou foi Removido.")->flash();
                redirect("/app/financCertificado/{$data['user_id']}/{$data['aca_id']}");
            }


            $atuAtualizar = (new Karatecas())->findById($certificadoAtivar->alu_id);
            if(!$atuAtualizar){
                $this->message->warning("Desculpe, mas você esta tentando atualizar um aluno que não existe ou foi Removido.")->flash();
                redirect("/app/financCertificado/{$data['user_id']}/{$data['aca_id']}");
            }
            $atuAtualizar->faixa_id = $atuAtualizar->faixa_id + 1;
            if(!$atuAtualizar->save()){
                $json["message"] = $atuAtualizar->message()->render();
                echo json_encode($json);
                return;
            }


            $certMes = date('m');
            $aluCertificadoAutentica = $certMes.$certificadoAtivar->user_id.$certificadoAtivar->aca_id.$certificadoAtivar->alu_id.$certificadoAtivar->faixaId;

            //Valodação de valores, Se < 40 = 4, se maior que 100 = 15%, se meio termo 10%
            if($certificadoAtivar->valorExame < '40'){
                $valorSistema = '4';
            }elseif ($certificadoAtivar->valorExame > '100') {
                $valorSistema = $certificadoAtivar->valorExame * 15/100;
            }else{
                $valorSistema = $certificadoAtivar->valorExame * 10/100;
            }

            $cadFinanceiro = (new Financeiro());
            $cadFinanceiro->referencia = '4';
            $cadFinanceiro->user_id = $certificadoAtivar->user_id;
            $cadFinanceiro->aca_id = $certificadoAtivar->aca_id;
            $cadFinanceiro->alu_id = $certificadoAtivar->alu_id;
            $cadFinanceiro->cert_id = $certificadoAtivar->id;
            $cadFinanceiro->valor = $certificadoAtivar->valorExame;
            $cadFinanceiro->valorFekto = $certificadoAtivar->valorExame - $valorSistema;
            $cadFinanceiro->valorSistema = $valorSistema;
            $cadFinanceiro->status = '0';
            $cadFinanceiro->tipo = 1;
            $cadFinanceiro->criado = date("Y-m-d H:i:s");

            if(!$cadFinanceiro->save()){
                $json["message"] = $cadFinanceiro->message()->render();
                echo json_encode($json);
                return;
            }

            $certificadoAtivar->certAutentica = $aluCertificadoAutentica;
            $certificadoAtivar->certStatus = '1';

            if(!$certificadoAtivar->save()){
                $json["message"] = $certificadoAtivar->message()->render();
                echo json_encode($json);
                return;
            }

            $this->message->success("Diploma criado e autenticado com sucesso. Oss.")->flash();
            redirect("/app/financCertificado/{$data['user_id']}/{$data['aca_id']}");
            return;
        }

        $certificadoVerifi = (new Certificados())->findJoin("certificado.user_id = :user_id AND certificado.aca_id = :aca_id AND certificado.certStatus = '3'",
            "user_id={$data['user_id']}&aca_id={$data['aca_id']}",
            "certificado.*,
            faixaAnterior.faixaNome AS faixa_anterior,
            faixaProxima.faixaNome AS faixa_proxima,
            (SELECT COUNT(certificado.aca_id) FROM certificado WHERE certificado.aca_id = :aca_id AND certStatus = '3') AS total_certificados",
            "
            INNER JOIN faixa faixaAnterior ON faixaAnterior.faixaId = certificado.faixaIdAnterio
            INNER JOIN faixa faixaProxima ON faixaProxima.faixaId = certificado.faixaId   
            ")->fetch(true);
        if(!$certificadoVerifi){
            $certificadoVerifi = null;
        }
//        var_dump($certificadoVerifi);
        $head = $this->seo->render(
            'Financeiro Certificado | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("financeiro/financeiroCertificado", [
            "head" => $head,
            "certificados" => $certificadoVerifi
        ]);
    }



    //LISTAR CERTIFICADOS ATIVADOS
    public function financCertAtivos(?array $data): void
    {
        if ($this->user->level < 3) {
            $this->message->warning("Desculpe, mas você não tem permissão para acessar este painel de controle.")->flash();
            redirect("/app");
        }

        $certificadoVerifi = (new Certificados())->findJoin("certStatus = '1' GROUP BY aca_id ASC", "",
            "certificado.*,
            academia.id, academia.acaNome AS nomeAcademia,
            usuario.name, usuario.sobrenome,
            (SELECT COUNT(certificado.aca_id) FROM certificado WHERE certificado.aca_id = academia.id AND certStatus = '1') AS total_certificados",
            "
            LEFT JOIN academia ON academia.id = certificado.aca_id
            LEFT JOIN usuario ON usuario.id = certificado.user_id
            ")->fetch(true);
        if(!$certificadoVerifi){
            $certificadoVerifi = null;
        }

        $head = $this->seo->render(
            'Financeiro PROFESSOR FEKTO | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("financeiro/financeiroCertificadosAtivos", [
            "head" => $head,
            "certificados" => $certificadoVerifi
        ]);
    }



    public function financCertGerirAtivos(?array $data): void
    {
        $data = filter_var_array($data, FILTER_SANITIZE_STRIPPED);

        if ($this->user->level < 3) {
            $this->message->warning("Desculpe, mas você não tem permissão para acessar este painel de controle.")->flash();
            redirect("/app");
        }

        if(!empty($data["cert_id"])) {
            $certificadoAtivar = (new Certificados())->findById($data['cert_id']);

            if(!$certificadoAtivar){
                $this->message->warning("Desculpe, mas você esta tentando ativar um Diploma que não existe ou foi Removido.")->flash();
                redirect("/app/financCertificado/{$data['user_id']}/{$data['aca_id']}");
            }




            $atuAtualizar = (new Karatecas())->findById($certificadoAtivar->alu_id);
            if(!$atuAtualizar){
                $this->message->warning("Desculpe, mas você esta tentando atualizar um aluno que não existe ou foi Removido.")->flash();
                redirect("/app/financCertificado/{$data['user_id']}/{$data['aca_id']}");
            }



            $atuAtualizar->faixa_id = $atuAtualizar->faixa_id - 1;
            if(!$atuAtualizar->save()){
                $json["message"] = $atuAtualizar->message()->render();
                echo json_encode($json);
                return;
            }

            $removeFinanPag = (new Financeiro())->findJoin("cert_id = :cert_id","cert_id={$data['cert_id']}")->fetch();
            if(!$removeFinanPag){
                $this->message->warning("Desculpe, esta referência de pagamento não foi encontrada no financeiro.")->flash();
//                redirect("/app/financCertificado/{$data['user_id']}/{$data['aca_id']}");
            }

//            $certiId = "existe o cert_id = ".$data["cert_id"];
//            var_dump($removeFinanPag, $certiId, $data, $certificadoAtivar, $atuAtualizar);
//            exit();

            $removeFinanPag->destroy();
            $certificadoAtivar->destroy();

            $this->message->success("Diploma excluido com sucesso e dados de aluno atualizado para faixa anterior. Oss.")->flash();
            redirect("/app/financCertificadoAtivados/{$data['user_id']}/{$data['aca_id']}");
            return;
        }

        $certificadoVerifi = (new Certificados())->findJoin("certificado.user_id = :user_id AND certificado.aca_id = :aca_id AND certificado.certStatus = '1'",
            "user_id={$data['user_id']}&aca_id={$data['aca_id']}",
            "certificado.*,
            faixaAnterior.faixaNome AS faixa_anterior,
            faixaProxima.faixaNome AS faixa_proxima,
            academia.acaNome,
            usuario.name, usuario.sobrenome,
            (SELECT COUNT(certificado.aca_id) FROM certificado WHERE certificado.aca_id = :aca_id AND certStatus = '1') AS total_certificados",
            "
            INNER JOIN faixa faixaAnterior ON faixaAnterior.faixaId = certificado.faixaIdAnterio
            INNER JOIN faixa faixaProxima ON faixaProxima.faixaId = certificado.faixaId
            LEFT JOIN academia ON academia.id = certificado.aca_id
            LEFT JOIN usuario ON usuario.id = certificado.user_id   
            ")->order("id DESC")->fetch(true);
        if(!$certificadoVerifi){
            $certificadoVerifi = null;
        }else {
//            foreach ($certificadoVerifi as $certEditar):
//
//            if(empty($certEditar->nome_aca)){
//                var_dump($certEditar);
//                $nomeProfessor = $certEditar->name .' '. $certEditar->sobrenome;
//                $sqlUpdate = "UPDATE certificado SET nome_pro = '$nomeProfessor', nome_aca = '$certEditar->acaNome', tipo = '$certEditar->tipo'   WHERE id = '$certEditar->id'";
//                $sqlUpdate = Connect::getInstance()->prepare($sqlUpdate);
//                $sqlUpdate->execute();
//            }
//            endforeach;
        }
//        var_dump($certificadoVerifi);
        $head = $this->seo->render(
            'Financeiro Certificado | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("financeiro/financeiroCertificadoAtivo", [
            "head" => $head,
            "certificados" => $certificadoVerifi
        ]);
    }



    public function financCamp(?array $data): void
    {
        $campeonato = (new Post())->findJoin("category = '2' AND dtevento <= NOW() AND del = '0'",
            "",
            "posts.*,
                cidades.cidNome,
                (SELECT SUM(ranking.valor) FROM ranking WHERE ranking.cam_id = posts.id) AS valor_total,
                (SELECT COUNT(ranking.cam_id) FROM ranking WHERE ranking.cam_id = posts.id) AS alunos_inscritos",
            "LEFT JOIN cidades ON cidades.cidId = posts.cidade
        ");

        $head = $this->seo->render(
            'Valores Anuidades | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app/valoresAnuidades",
            theme("/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("financeiro/financeiroCampeonato", [
            "head" => $head,
            "campeonatoAtivo" => $campeonato->order("dtevento DESC")->fetch(true)
        ]);
    }

    public function auditarCamp(?array $data): void
    {
        $auditarCamp = (new Ranking())->findJoin("cam_id = :cam_id",
            "cam_id={$data['cam_id']}",
            "ranking.*,
                cidades.cidNome,
                posts.title, posts.cidade, posts.dtevento,
                karateca.aluNome,
                usuario.name, usuario.sobrenome,
                academia.acaNome,
                (SELECT SUM(ranking.valor) FROM ranking WHERE ranking.cam_id = posts.id) AS valor_total,
                (SELECT COUNT(ranking.cam_id) FROM ranking WHERE ranking.cam_id = posts.id) AS alunos_inscritos",
            "INNER JOIN posts ON posts.id = :cam_id
                  INNER JOIN cidades ON cidades.cidId = posts.cidade
                  INNER JOIN karateca ON karateca.id = ranking.alu_id
                  INNER JOIN usuario ON usuario.id = ranking.user_id
                  INNER JOIN academia ON academia.id = ranking.aca_id
        ")->order("acaNome ASC, name")->fetch(true);
        $head = $this->seo->render(
            'Valores Anuidades | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app/valoresAnuidades",
            theme("/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("financeiro/auditarCampeonato", [
            "head" => $head,
            "auditar" => $auditarCamp
        ]);
    }



    /**********************************************************************************************
    GERENCIAMENTO DE VALORES SITE
     *********************************************************************************************/

    public function valoresFaixa(?array $data): void
    {
        if(Auth::user()->level < 3){
            $this->message->error("Você não tem permissão para editar Campeonatos...")->flash();
            redirect("/app");
            return;
        }

        if(!empty($data["action"]) && $data["action"] == "update") {
            foreach ($data["valor"] as $key => $value) {
                $valorFormat = str_replace([".", ","], ["", "."], $value);
                $sqlUpdate = "UPDATE faixa SET valor = '$valorFormat' WHERE faixaId = '$key'";
                $sqlUpdate = Connect::getInstance()->prepare($sqlUpdate);
                $sqlUpdate->execute();
            }

            foreach ($data["valorProj"] as $key => $value) {
                $valorFormat = str_replace([".", ","], ["", "."], $value);
                $sqlUpdate = "UPDATE faixa SET valorProj = '$valorFormat' WHERE faixaId = '$key'";
                $sqlUpdate = Connect::getInstance()->prepare($sqlUpdate);
                $sqlUpdate->execute();
            }
            $this->message->error("Você não tem permissão para editar Campeonatos...")->flash();
            $json["redirect"] = url("/app/valoresAnuidades");
            echo json_encode($json);
        }

        $valorFaixa = (new Faixa())->find()->fetch(true);


        $head = $this->seo->render(
            'Valores Anuidades | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app/valoresAnuidades",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("financeiro/valorFaixa", [
            "head" => $head,
            "varloFaixa" => $valorFaixa
        ]);
    }


    public function valoresExames(?array $data): void
    {
        if(Auth::user()->level < 3){
            $this->message->error("Você não tem permissão para editar Campeonatos...")->flash();
            redirect("/app");
            return;
        }

        if(!empty($data["action"]) && $data["action"] == "update") {
            foreach ($data["valor"] as $key => $value) {
                $valorFormat = str_replace([".", ","], ["", "."], $value);
                $sqlUpdate = "UPDATE faixa SET valorExame = '$valorFormat' WHERE faixaId = '$key'";
                $sqlUpdate = Connect::getInstance()->prepare($sqlUpdate);
                $sqlUpdate->execute();
            }

            foreach ($data["valorProj"] as $key => $value) {
                $valorFormat = str_replace([".", ","], ["", "."], $value);
                $sqlUpdate = "UPDATE faixa SET valorExameProj = '$valorFormat' WHERE faixaId = '$key'";
                $sqlUpdate = Connect::getInstance()->prepare($sqlUpdate);
                $sqlUpdate->execute();
            }
        }

        $valorFaixa = (new Faixa())->find()->fetch(true);


        $head = $this->seo->render(
            'Valores Anuidades | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app/valoresAnuidades",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("financeiro/valorExameFaixa", [
            "head" => $head,
            "varloFaixa" => $valorFaixa
        ]);
    }

    /**********************************************************************************************
    GERENCIAMENTO DE DADOS FEKTO
     *********************************************************************************************/

    public function perfilFekto(?array $data): void
    {
        if(Auth::user()->level < 3){
            $this->message->error("Você não tem permissão para editar Campeonatos...")->flash();
            redirect("/app");
            return;
        }

        if(!empty($data["action"]) && $data["action"] == "update") {

//            list($d, $m, $y) = explode("/", $data["nasc"]);
            $upEmpresa = (new Empresa())->find()->fetch();
            $upEmpresa->name = $data["name"];
            $upEmpresa->cnpj = preg_replace("/[^0-9]/", "", $data["cnpj"]);
            $upEmpresa->sigla = $data["sigla"];
            $upEmpresa->email = $data["email"];
            $upEmpresa->celular1 = preg_replace("/[^0-9]/", "", $data["celular1"]);
            $upEmpresa->celular2 = preg_replace("/[^0-9]/", "", $data["celular2"]);
            $upEmpresa->endereco = $data["endereco"];
            $upEmpresa->website = $data["website"];
            $upEmpresa->city = $data["cidade"];
            $upEmpresa->state = $data["state"];
            $upEmpresa->cep = preg_replace("/[^0-9]/", "", $data["cep"]);

            if (!validar_cnpj($upEmpresa->cnpj)) {
                $json["message"] = $this->message->error("O CNPJ informado não é válido \"{$upEmpresa->cnpj}\"!")->render();
                echo json_encode($json);
                return;
            }

            if(!empty($_FILES["photo"])){
                if($upEmpresa->photo && file_exists(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$upEmpresa->photo}")){
                    unlink(__DIR__ . "/../../". CONF_UPLOAD_DIR ."/{$upEmpresa->photo}");
                    (new Thumb())->flush($upEmpresa->photo);
                }
                $files = $_FILES["photo"];
                $upload = new Upload();
                $image = $upload->image($files, $upEmpresa->name, 500);

                if(!$image){
                    $json["message"] = $upload->message()->render();
                    echo json_encode($json);
                    return;
                }
                $upEmpresa->photo = $image;
            }

//            if(!$upEmpresa->save()){
//                var_dump($data, $upEmpresa);
//                $json["message"] = $upEmpresa->message()->render();
//                echo json_encode($json);
//                return;
//            }
//
//            $this->message->success("Você atualizaou com sucesso as informações da empresa...")->flash();
//            $json["redirect"] = url("/app/perfilFekto");
//            echo json_encode($json);

            if(!$upEmpresa->save()){
                $json["message"] = $upEmpresa->message()->render();
                echo json_encode($json);
                return;
            }

            $json["message"] = $this->message->success("Pronto, os dados da empresa formam atualizados com sucesso!")->render();
            echo json_encode($json);
            return;
        }


        $head = $this->seo->render(
            'Valores Anuidades | ' . CONF_SITE_NAME,
            CONF_SITE_DESC,
            "/app/valoresAnuidades",
            theme("/assets/images/image.jpg"),
            false
        );
        echo $this->view->render("financeiro/perfilFekto", [
            "head" => $head,
            "empresa" => $getempresa = (new Empresa())->find()->fetch()
        ]);
    }





    public function getAllCidades()
    {
        $array = array();

        $sql = "SELECT cidId, cidNome FROM cidades";
        $sql = Connect::getInstance()->prepare($sql);
        $sql->execute();

        if($sql->rowCount() > 0){
            $array = $sql->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $array;
    }

    public function getAllFaixa($id = null)
    {
        $array = array();

        $sql = "SELECT * FROM faixa";
        $sql = Connect::getInstance()->prepare($sql);

        if(!empty($id)){
            $sql .= " WHERE faixaId ='$id'";
        }
        $sql->execute();

        if($sql->rowCount() > 0){
            $array = $sql->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $array;
    }

    public function getAllNivel()
    {
        $array = array();
        $sql = "SELECT * FROM level";
        $sql = Connect::getInstance()->prepare($sql);
        $sql->execute();

        if($sql->rowCount() > 0){
            $array = $sql->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $array;
    }
}
