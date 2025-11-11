<?php

namespace Tests\Fixtures\Document;

use Pillar\Aggregate\EventSourcedAggregateRoot;
use Pillar\Aggregate\RecordsEvents;
use Pillar\Snapshot\Snapshottable;

final class Document implements EventSourcedAggregateRoot, Snapshottable
{
    use RecordsEvents;

    private DocumentId $id;
    private string $title;

    public static function create(DocumentId $id, string $title): self
    {
        $self = new self();
        $self->record(new DocumentCreated($id, $title));
        return $self;
    }

    public function rename(string $newTitle): void
    {
        if ($this->title === $newTitle) {
            return;
        }
        $this->record(new DocumentRenamed($this->id, $newTitle));
    }

    protected function applyDocumentCreated(DocumentCreated $event): void
    {
        $this->id = $event->id;
        $this->title = $event->title;
    }

    protected function applyDocumentRenamed(DocumentRenamed $event): void
    {
        $this->title = $event->title;
    }

    public function id(): DocumentId
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public static function fromSnapshot(array $data): static
    {
        $self = new Document();
        $self->id = DocumentId::from($data['id']);
        $self->title = $data['title'];
        return $self;
    }

    public function toSnapshot(): array
    {
        return [
            'id' => $this->id->value(),
            'title' => $this->title,
        ];
    }
}