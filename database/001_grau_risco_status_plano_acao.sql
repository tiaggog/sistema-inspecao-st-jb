-- Migração 001: alinha grau de risco e status das NCs com as opções do Plano de Ação.
--
-- A tela preencher_plano.php oferece os riscos Baixo/Médio/Alto/Crítico e o status
-- "Aguardando aprovação da diretoria", mas a tabela só aceitava a escala
-- Intolerável/Substancial/Moderado/Aceitável/Trivial. Valores fora do ENUM
-- eram gravados em branco.
--
-- Executar uma única vez:  mysql -u root seguranca_checklist < database/001_grau_risco_status_plano_acao.sql

SET NAMES utf8mb4;

-- 1) Valores gravados em branco ficam sem risco definido
UPDATE respostas_checklist SET grau_risco = NULL WHERE grau_risco = '';

-- 2) Amplia o ENUM temporariamente para aceitar as duas escalas
ALTER TABLE respostas_checklist
    MODIFY grau_risco ENUM('Intolerável','Substancial','Moderado','Aceitável','Trivial',
                           'Baixo','Médio','Alto','Crítico') DEFAULT NULL;

-- 3) Converte os valores antigos para a nova escala
UPDATE respostas_checklist SET grau_risco = CASE grau_risco
        WHEN 'Intolerável' THEN 'Crítico'
        WHEN 'Substancial' THEN 'Alto'
        WHEN 'Moderado'    THEN 'Médio'
        WHEN 'Aceitável'   THEN 'Baixo'
        WHEN 'Trivial'     THEN 'Baixo'
        ELSE grau_risco
    END;

-- 4) Mantém apenas a escala usada pelo sistema
ALTER TABLE respostas_checklist
    MODIFY grau_risco ENUM('Baixo','Médio','Alto','Crítico') DEFAULT 'Médio';

-- 5) Inclui o status intermediário usado no Plano de Ação
ALTER TABLE respostas_checklist
    MODIFY status ENUM('Pendente','Em andamento','Aguardando aprovação da diretoria','Concluído') DEFAULT 'Pendente';
