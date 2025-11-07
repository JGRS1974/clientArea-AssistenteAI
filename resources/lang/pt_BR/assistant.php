<?php

return [
    'follow_up' => [
        'default' => [
            'Precisa de outra consulta?',
            'Quer que eu verifique mais alguma informação?',
            'Posso conferir outro dado para você?',
        ],
    ],
    'login' => [
        'required' => [
            'generic' => [
                'Para acessar :label, é preciso estar logado. Por favor, faça login e me avise aqui quando terminar. Assim, consigo te ajudar!'
            ],
            'ir' => [
                'Para acessar seu informe de rendimentos (IR), é preciso estar logado. Por favor, faça login e me avise aqui quando terminar. Assim, consigo te ajudar!'
            ],
        ],
    ],
    'ticket' => [
        'none' => [
            'Não encontrei boletos disponíveis no momento.',
            'Não encontrei boletos em aberto para este CPF.',
            'Nenhum boleto disponível no momento.',
            'Não há cobranças em aberto agora. Se preferir, posso conferir outro CPF.',
        ],
        'mixed' => [
            'Encontrei boletos em aberto e outros vencidos. Os indisponíveis mostram o motivo na lista.',
            'Há boletos em aberto e outros vencidos. Copie a linha digitável dos disponíveis para pagar no app do banco.',
            'Localizei cobranças: algumas em aberto e outras vencidas. O link dos disponíveis expira em 1 hora.',
            'Encontrei boletos disponíveis e vencidos; os indisponíveis indicam o motivo na lista.',
        ],
        'expired' => [
            'Não encontrei boletos disponíveis; os registros atuais estão vencidos.',
            'Constam apenas boletos vencidos. O motivo aparece em cada item da lista.',
            'Boletos indisponíveis no momento (vencidos). Veja as justificativas na lista.',
        ],
        'errors' => [
            'cpf_invalid' => [
                'Esse CPF não parece válido. Pode me enviar novamente, só os números (11 dígitos)?',
                'CPF inválido. Por favor, envie 11 dígitos (somente números).',
            ],
            // Validações de segurança/temporárias (substituem menção a "PIN")
            'validation_failed' => [
                'Falha na validação da consulta. Posso tentar novamente agora?',
                'Não consegui validar a consulta desta vez. Refaço a busca para você?',
            ],
            'technical' => [
                'Tive um problema técnico ao consultar seus boletos. Quer que eu tente novamente agora?',
                'Ocorreu um erro temporário na consulta. Posso refazer a busca?',
            ],
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
        'fichafinanceira' => 'seus pagamentos',
        'coparticipacao' => 'sua coparticipação',
        'beneficiarios' => 'sua carteirinha',
        'default' => 'sua carteirinha',
    ],
];
