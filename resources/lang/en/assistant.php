<?php

return [
    'follow_up' => [
        'default' => [
            'Posso ajudar em mais alguma coisa?',
            'Quer apoio com mais algum assunto?',
            'Precisa de algo mais?',
            'Posso ajudar com outra dúvida?',
        ],
    ],
    'login' => [
        'required' => [
            'generic' => [
                'Você precisa estar logado para consultar :label.<br>Faça login e me avise, por favor. 🙂',
                'Você precisa estar logado para consultar :label.<br>Faça login e me avise, obrigado.',
                'Para acessar :label, faça login e me avise quando concluir. Obrigado. 🙂',
                'Faça login para liberar :label e me avise ao terminar, por favor. 🙂',
            ],
            'ir' => [
                'Você precisa estar logado para consultar seu informe de rendimentos.<br>Faça login e me avise, por favor. 🙂',
                'Para liberar o informe de rendimentos, realize o login e me avise. 🙂',
            ],
        ],
    ],
    'ticket' => [
        'none' => [
            'Não encontrei boletos disponíveis no momento.',
        ],
        'mixed' => [
            'Encontrei boletos em aberto e outros vencidos. Os indisponíveis mostram o motivo na lista.',
        ],
        'expired' => [
            'Não encontrei boletos disponíveis; os registros atuais estão vencidos.',
        ],
    ],
    'card' => [
        'planos' => [
            'not_found' => [
                'Não encontrei planos associados ao seu CPF.',
            ],
            'filtered_not_found' => [
                'Não identifiquei planos relacionados a :terms. Quer que eu traga todos os planos disponíveis?',
                'Não achei planos que correspondam a :terms. Posso mostrar a lista completa para conferirmos?',
            ],
        ],
        'beneficiarios' => [
            'not_found' => [
                'Não encontrei carteirinhas vinculadas ao seu CPF.',
            ],
        ],
        'fichafinanceira' => [
            'not_found' => [
                'Não encontrei pagamentos registrados para esta consulta.',
                'Não localizei lançamentos financeiros para esta consulta.',
            ],
            'partial' => [
                'Mostrei os lançamentos disponíveis; alguns planos não possuem registros.',
                'Alguns pagamentos estão visíveis; outros planos não têm lançamentos nesse período.',
            ],
            'no_entries' => [
                'Encontrei :plans_label, mas não há pagamentos lançados para ele.',
                'Localizei :plans_label, porém não existem pagamentos registrados neste período.',
            ],
        ],
        'coparticipacao' => [
            'not_found' => [
                'Não encontrei registros de coparticipação para esta consulta.',
            ],
            'partial' => [
                'Exibi as coparticipações disponíveis; alguns planos não possuem registros.',
            ],
            'no_entries' => [
                'Encontrei :plans_label, mas não há registros de coparticipação para ele.',
                'Localizei :plans_label, porém não existem coparticipações registradas neste período.',
            ],
        ],
    ],
    'cpf_request' => [
        'default' => 'Por favor, envie seu CPF (somente números) para eu continuar a consulta.',
        'planos' => 'Pode me informar seu CPF (somente números) para eu buscar seus planos?',
        'fichafinanceira' => 'Preciso do seu CPF (somente números) para exibir seus pagamentos.',
        'coparticipacao' => 'Me informe seu CPF (somente números) para eu consultar sua coparticipação.',
    ],
    'labels' => [
        'planos' => 'seus planos',
        'fichafinanceira' => 'seus pagamentos (relatório financeiro)',
        'coparticipacao' => 'sua coparticipação',
        'beneficiarios' => 'sua carteirinha',
        'default' => 'sua carteirinha',
    ],
];
