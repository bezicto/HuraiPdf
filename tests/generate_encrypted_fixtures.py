"""Optional fixture regeneration only: requires pypdf and cryptography.

The committed synthetic PDFs allow the PHP regression suite to run with no
Python or third-party dependencies. No production documents are used.
"""
from pathlib import Path
from io import BytesIO
from pypdf import PdfWriter
from pypdf.generic import DictionaryObject, NameObject, DecodedStreamObject, StreamObject, NumberObject, ArrayObject, BooleanObject

target = Path(__file__).parent / 'fixtures'
target.mkdir(exist_ok=True)


def write_object_stream_pdf(writer, path):
    """Serialize real pypdf-encrypted streams into an xref-stream document.

    Strings inside ObjStm are serialized in plaintext and encrypted exactly
    once as part of their container, including the compressed Info dictionary.
    """
    compressed = {}
    header, body = b'', b''
    for number, obj in enumerate(writer._objects, 1):
        if isinstance(obj, StreamObject) or obj is writer._encrypt_entry:
            continue
        compressed[number] = len(compressed)
        header += f'{number} {len(body)} '.encode()
        value = BytesIO()
        obj.write_to_stream(value)
        body += value.getvalue() + b'\n'
    container_id = len(writer._objects) + 1
    xref_id = container_id + 1
    container = DecodedStreamObject()
    container.set_data(header + body)
    container.update({NameObject('/Type'): NameObject('/ObjStm'),
                      NameObject('/N'): NumberObject(len(compressed)),
                      NameObject('/First'): NumberObject(len(header))})
    out = BytesIO(b'%PDF-1.7\n')
    out.seek(0, 2)
    offsets = {}
    for number, obj in enumerate([*writer._objects, container.flate_encode()], 1):
        if number in compressed:
            continue
        offsets[number] = out.tell()
        out.write(f'{number} 0 obj\n'.encode())
        if obj is not writer._encrypt_entry:
            obj = writer._encryption.encrypt_object(obj, number, 0)
        obj.write_to_stream(out)
        out.write(b'\nendobj\n')
    offsets[xref_id] = out.tell()
    entries = b'\x00' + b'\x00' * 4 + b'\xff\xff'
    for number in range(1, xref_id + 1):
        if number in compressed:
            entries += b'\x02' + container_id.to_bytes(4, 'big') + compressed[number].to_bytes(2, 'big')
        else:
            entries += b'\x01' + offsets[number].to_bytes(4, 'big') + b'\x00\x00'
    xref = DecodedStreamObject()
    xref.set_data(entries)
    xref.update({NameObject('/Type'): NameObject('/XRef'), NameObject('/Size'): NumberObject(xref_id + 1),
                 NameObject('/W'): ArrayObject([NumberObject(n) for n in [1, 4, 2]]),
                 NameObject('/Root'): writer.root_object.indirect_reference,
                 NameObject('/Info'): writer._info.indirect_reference,
                 NameObject('/ID'): writer._ID, NameObject('/Encrypt'): writer._encrypt_entry.indirect_reference})
    out.write(f'{xref_id} 0 obj\n'.encode())
    xref.flate_encode().write_to_stream(out)
    out.write(f'\nendobj\nstartxref\n{offsets[xref_id]}\n%%EOF\n'.encode())
    path.write_bytes(out.getvalue())

for algorithm, revision in [('RC4-40', 2), ('RC4-128', 3), ('AES-128', 4), ('AES-256-R5', 5), ('AES-256', 6)]:
    for password in ['', 'secret']:
        writer = PdfWriter()
        font = writer._add_object(DictionaryObject({
            NameObject('/Type'): NameObject('/Font'),
            NameObject('/Subtype'): NameObject('/Type1'),
            NameObject('/BaseFont'): NameObject('/Helvetica'),
        }))
        for number in [1, 2]:
            page = writer.add_blank_page(width=612, height=792)
            page[NameObject('/Resources')] = DictionaryObject({NameObject('/Font'): DictionaryObject({NameObject('/F1'): font})})
            stream = DecodedStreamObject()
            stream.set_data(f'BT /F1 12 Tf (Page {number}) Tj 0 -12 Td (Invoice total 123) Tj ET'.encode())
            page[NameObject('/Contents')] = writer._add_object(stream.flate_encode())
        writer.add_metadata({'/Title': 'Encrypted invoice', '/Author': 'Author 😀', '/Subject': 'Permissions only',
                             '/CreationDate': 'D:20260906120000Z', '/ModDate': "D:20260906200000+08'00'"})
        writer.encrypt(password, 'owner-secret', algorithm=algorithm)
        name = f'r{revision}-' + ('password' if password else 'empty') + '.pdf'
        writer.write(target / name)
        if password == '' and revision in [4, 6]:
            write_object_stream_pdf(writer, target / f'r{revision}-object-stream.pdf')
            writer._encryption.EncryptMetadata = False
            entry = writer._encryption.write_entry('', 'owner-secret')
            entry[NameObject('/EncryptMetadata')] = BooleanObject(False)
            writer._encrypt_entry.clear()
            writer._encrypt_entry.update(entry)
            writer.write(target / f'r{revision}-metadata-clear.pdf')
        if password == '' and revision == 4:
            writer._encryption.StmF = '/V2'
            writer._encryption.StrF = '/V2'
            entry = writer._encryption.write_entry('', 'owner-secret')
            entry[NameObject('/EncryptMetadata')] = BooleanObject(False)
            writer._encrypt_entry.clear()
            writer._encrypt_entry.update(entry)
            writer.write(target / 'r4-rc4.pdf')
            writer._encryption.StmF = '/Identity'
            writer._encryption.StrF = '/AESV2'
            entry = writer._encryption.write_entry('', 'owner-secret')
            entry[NameObject('/EncryptMetadata')] = BooleanObject(False)
            entry[NameObject('/StmF')] = NameObject('/Identity')
            entry['/CF']['/StdCF'][NameObject('/CFM')] = NameObject('/AESV2')
            writer._encrypt_entry.clear()
            writer._encrypt_entry.update(entry)
            writer.write(target / 'r4-identity-stream.pdf')
