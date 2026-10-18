<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Contract\Adapter;

/**
 * Turns what an extractor produced into the common model (contract v1).
 *
 * A document has a header (project, commit, language…) and one or more big
 * lists that are uploaded in chunks. The adapter says which lists those are
 * and turns any chunk into normalized units:
 *
 * unit: key, kind, name, path, parent, language, category, is_test, lines,
 *   doc, extra, symbols[], refs[], endpoints[]
 * symbol: key, kind, name, qualified_name, parent, visibility, modifiers[],
 *   signature, type, value, file, line, end_line, exported, generated,
 *   deprecated, doc, extra, params[], refs[]
 * doc: summary, description, tags[{kind, name, text}]
 * param: kind (param|type_param|component), name, type, optional, rest,
 *   default, doc
 * ref: kind, target, display, external, line, count, from, detail
 * endpoint: kind (route|http_call|…), method, path, full_path, symbol, raw,
 *   line, detail
 *
 * Symbols come flat: members name their parent's key. Register adapters as
 * services tagged "divengine_codedocs.adapter".
 */
interface CodeDocsAdapterInterface {

  /**
   * Machine name, e.g. "javadoc".
   */
  public function id(): string;

  /**
   * Human name, e.g. "Javadoc (JSON doclet)".
   */
  public function label(): string;

  /**
   * Whether a document, or its header, is in this format.
   */
  public function applies(array $document): bool;

  /**
   * The lists of the document uploaded in chunks, in upload order.
   */
  public function chunkKeys(): array;

  /**
   * Facts of the header: project, language, producer, commit, branch,
   * captured_at.
   */
  public function meta(array $header): array;

  /**
   * The normalized units of a chunk.
   *
   * @throws \Drupal\divengine_codedocs\Contract\ContractException
   */
  public function units(array $chunk, array $header): array;

}
