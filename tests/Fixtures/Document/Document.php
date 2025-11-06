<?php

namespace Tests\Fixtures\Document;

use JsonSerializable;
use Pillar\Aggregate\AggregateRoot;

final class Document extends AggregateRoot implements JsonSerializable
{
    private DocumentId $id;
    private string $title;

    public static function create(DocumentId $id, string $title): self
    {
        $self = new self();
        $self->record(new DocumentCreated($id, $title));
        return $self;
    }

    protected function applyDocumentCreated(DocumentCreated $event): void
    {
        $this->id = $event->id;
        $this->title = $event->title;
    }

    public static function fromSnapshot(array $data): self
    {
        $self = new self();
        $self->id = DocumentId::from($data['id']);
        $self->title = $data['title'];
        return $self;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'id' => $this->id->value(),
            'title' => $this->title,
        ];
    }

    public function id(): DocumentId
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }
}