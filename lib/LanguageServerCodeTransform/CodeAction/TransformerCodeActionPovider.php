<?php

namespace Phpactor\Extension\LanguageServerCodeTransform\CodeAction;

use Amp\Promise;
use Amp\Success;
use Generator;
use Phpactor\CodeTransform\Domain\SourceCode;
use Phpactor\CodeTransform\Domain\Transformers;
use Phpactor\Extension\LanguageServerBridge\Converter\TextDocumentConverter;
use Phpactor\Extension\LanguageServerCodeTransform\Converter\DiagnosticsConverter;
use Phpactor\Extension\LanguageServerCodeTransform\LspCommand\TransformCommand;
use Phpactor\LanguageServerProtocol\CodeAction;
use Phpactor\LanguageServerProtocol\Command;
use Phpactor\LanguageServerProtocol\Diagnostic;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\LanguageServer\Core\CodeAction\CodeActionProvider;
use Phpactor\LanguageServer\Core\Diagnostics\DiagnosticsProvider;

class TransformerCodeActionPovider implements DiagnosticsProvider, CodeActionProvider
{
    /**
     * @var string
     */
    private $title;

    /**
     * @var Transformers
     */
    private $transformers;

    /**
     * @var string
     */
    private $name;

    public function __construct(Transformers $transformers, string $name, string $title)
    {
        $this->title = $title;
        $this->transformers = $transformers;
        $this->name = $name;
    }

    /**
     * {@inheritDoc}
     */
    public function kinds(): array
    {
        return [
            $this->kind()
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function provideDiagnostics(TextDocumentItem $textDocument): Promise
    {
        return new Success($this->getDiagnostics($textDocument));
    }

    /**
     * @return array<Diagnostic>
     */
    private function getDiagnostics(TextDocumentItem $textDocument): array
    {
        $phpactorTextDocument = TextDocumentConverter::fromLspTextItem($textDocument);

        return array_map(function (Diagnostic $diagnostic) {
            $diagnostic->message = sprintf('%s (fix with "%s" code action)', $diagnostic->message, $this->title);
            return $diagnostic;
        }, DiagnosticsConverter::toLspDiagnostics(
            $phpactorTextDocument,
            $this->transformers->get($this->name)->diagnostics(
                SourceCode::fromStringAndPath($textDocument->text, $phpactorTextDocument->uri()->path())
            )
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function provideActionsFor(TextDocumentItem $textDocument, Range $range): Generator
    {
        if (0 === count($this->getDiagnostics($textDocument))) {
            return;
        }

        yield CodeAction::fromArray([
            'title' =>  $this->title,
            'kind' => $this->kind(),
            'diagnostics' => $this->getDiagnostics($textDocument),
            'command' => new Command(
                $this->title,
                TransformCommand::NAME,
                [
                    $textDocument->uri,
                    $this->name
                ]
            )
        ]);
    }

    private function kind(): string
    {
        return 'quickfix.'.$this->name;
    }
}
