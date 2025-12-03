import { useState, useRef, useEffect } from "react";
import { Text, Badge, ActionIcon, Button, Flex, Modal, ScrollArea, Image } from '@mantine/core';
import { Dropzone, IMAGE_MIME_TYPE, PDF_MIME_TYPE, MS_WORD_MIME_TYPE, MS_EXCEL_MIME_TYPE, MS_POWERPOINT_MIME_TYPE } from '@mantine/dropzone';
import { IconUpload, IconX, IconDownload, IconFile, IconPhoto, IconFileTypePdf, IconFileWord  } from '@tabler/icons-react';
import { getConfig } from "../utils";
import { FileData } from "../types/types";

type Props = {
  label?: string,
  desc: string,
  maxFiles: number,
  maxSize: number,
  readOnly?: boolean,
  existingfiles?: FileData[],
  setState: (value: string) => void
  showPreview?: boolean
  mimeTypes?: string[]
}


export function FileUploader ({label, desc, maxFiles, maxSize, readOnly, existingfiles, setState, showPreview, mimeTypes}: Props) {
  const openRef = useRef<() => void>(null);
  
  const [fileData, setFileData] = useState<FileData[]>([]);
  const [previews, setPreviews] = useState<(false | JSX.Element)[]>([]);
  const [error, setError] = useState<string>('');
  const [downloadFile, setDownloadFile] = useState<FileData|null>(null);

  // Add existing files to control. 
  useEffect(() => {
    if (!existingfiles || !existingfiles.length) {
      return
    }
    // Ensure that existing data is only added to control once. Don't add duplicates.
    const uniqueExisting = existingfiles.filter(function (file: FileData) {
      // Search for matching file already in fileData.
      return !fileData.find((obj: FileData) => {
        return obj.serverfilename === file.serverfilename
      })
    })
    
    // Only update if there are actually new files to add
    if (uniqueExisting.length === 0) {
      return
    }
    
    const currPosition = fileData.length;
    const dressedExistingFiles = uniqueExisting.map((file: FileData, index: number) => {
      // Create a filedata obj for any newly added files.
      return {
        index: currPosition + index,
        displayname: file.displayname,
        file: null,
        progress: 0,
        started: true,
        completed: true,
        removed: false,
        serverfilename: file.serverfilename,
        existing: true,
        key: file.fileid,
        path: file.path,
        fileid: file.fileid,
        mimetype: file.mimetype || '',
      } as FileData;
    })
    // Append the dropped files to the fileData array.
    const allFileData = [...fileData, ...dressedExistingFiles]
    setFileData(allFileData)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [existingfiles?.map(f => f.serverfilename).join(',')])

  // Append dropped files.
  const handleDrop = (droppedFiles: File[]) => {
    setError('')
    const currPosition = fileData.length;

    const countActive = fileData.filter((file) => !file.removed).length;
    if (countActive >= maxFiles) {
      setError(maxFiles + ' file' + (maxFiles > 1 ? 's' : '') + ' is the maximum you can add here');
      return
    }

    // Remove any duplicates.
    const cleanDroppedFiles = droppedFiles.filter(function (file) {
      // Search for fileData with key that matches this droppedFiles name.
      return !fileData.find(obj => {
        return obj.displayname === file.name
      })
    })

    const droppedFileData = cleanDroppedFiles.map((file, index) => {
      // Create a filedata obj for any newly added files.
      return {
        index: currPosition + index,
        displayname: file.name,
        file: file,
        progress: 0,
        started: false,
        completed: false,
        removed: false,
        serverfilename: '',
        existing: false,
        key: '',
        path: '',
        fileid: '',
        mimetype: file.type || '',
      } as FileData;
    })

    // Append the dropped files to the fileData array.
    const allFileData = [...fileData, ...droppedFileData]
    setFileData(allFileData)
  }

  // Side effect of new files being added.
  useEffect(() => {
    // Do not proceed with empty.
    if (!fileData.length) {
      return
    }

    // Check if any uploads are new/not started.
    const waiting = fileData.find(file => file.started === false)
    if (waiting === undefined) {
      return
    }

    // Start upload for any newly added files.
    let fileDataCopy = [...fileData];
    for (let i = 0; i < fileDataCopy.length; i++) {
      if (!fileDataCopy[i].started) {
        uploadFile(fileDataCopy[i])
        fileDataCopy[i].started = true
      }
    }
    setFileData([...fileDataCopy]);
  }, [fileData]);


  const uploadFile = (file: FileData) => {
    const formData = new FormData();
    formData.append('file', file.file!)
    const xhr = new XMLHttpRequest();
    xhr.upload.onprogress = e => {
      let f = {...file};
      if (e.lengthComputable) {
        if (!f.completed) {
          const progress = Math.ceil(((e.loaded) / e.total) * 100);
          f.progress = progress;
          setFileProgress(f);
        }
      }
    };
    xhr.onreadystatechange = () => {
      let f = {...file};
      if (xhr.readyState !== 4) {
        return;
      }
      if (xhr.status !== 200) {
        console.log('Error' + xhr.status);
      }
      if (xhr.readyState == XMLHttpRequest.DONE) {
        const data = JSON.parse(xhr.responseText);
        f.progress = 100;
        f.completed = true;
        f.serverfilename = data.name;
        f.key = data.name;
        setFileProgress(f);
      }
    };
    const url = getConfig().wwwroot + '/local/announcements2/upload.php?upload=1&sesskey=' + getConfig().sesskey;
    xhr.open('POST', url); //'https://httpbin.org/post');
    xhr.send(formData)
  }
  

  const setFileProgress = (file: FileData) => {
    // File upload progressed. Update filedata which will trigger badge re-render.
    setFileData(prevFileData => {
      let fileDataCopy = [...prevFileData]
      if (fileDataCopy[file.index].started && (file.completed || fileDataCopy[file.index].progress < file.progress)) {
        // Replace this file obj.
        fileDataCopy[file.index] = {...file}
      }
      return fileDataCopy
    });
  }


  /***************
   * Handle Previews
   * *************/
  const handleRemove = (deleteIndex: number) => {
    setError('')
    // Remove the file from fileData.
    setFileData(fileData => {
      let copy = [...fileData]
      for (let i = 0; i < copy.length; i++) {
        if (copy[i].index == deleteIndex) {
          // Remove the temp file from server.
          if (!copy[i].existing && copy[i].completed && copy[i].serverfilename) {
            const url = getConfig().wwwroot + '/local/announcements2/upload.php?remove=1&fileid=' + copy[i].serverfilename + "&sesskey=" + getConfig().sesskey
            const xhr = new XMLHttpRequest()
            xhr.open("GET", url)
            xhr.send()
          }
          copy[i] = { ...copy[i], displayname: '', started: true, completed: true, removed: true, serverfilename: '' } as FileData
        } 
      }
      return copy
    });
  }

  const removeButton = (index: number) => (
    <ActionIcon color="dark" size="xs" radius="xl" variant="light" onClick={() => { handleRemove(index) }}>
      <IconX size="10rem" />
    </ActionIcon>
  );

  // Another side effect of fileData changing during upload process.
  useEffect(() => {
    // Do not proceed with empty.
    if (!fileData.length) {
      return
    }

    // Generate new previews.
    const newPreviews = fileData.map((file, index) => {
      if (file.existing) {
        return false;
      }
      if (file.removed) {
        return false;
      }
      const color = file.completed ? "teal" : "gray";
      return (
        <Badge 
          key={file.index}
          color={color} rightSection={removeButton(file.index)} 
          variant="light" 
          size="lg" 
          className="cursor-pointer pl-1 pr-1"
          leftSection={file.displayname.toLowerCase().includes(".doc")
            ? <IconFileWord className='size-5' /> 
            : file.displayname.toLowerCase().includes(".pdf") 
              ? <IconFileTypePdf className='size-5' />
              : [".jpg", ".jpeg", ".png", ".gif", ".webp", ".svg"].some(ext => file.displayname.toLowerCase().endsWith(ext))
                ? <IconPhoto className='size-5' />
                : <IconFile className='size-5' />
          }
        >
          <div onClick={() => setDownloadFile(file)} className="flex items-center gap-1">
            <Text tt="none">{file.displayname}</Text>
          </div>
        </Badge>
      )
    })
    const newWithoutEmpties = newPreviews.filter(badge => badge)

    const existingPreviews = fileData.map((file, index) => {
      if (!file.existing) {
        return false;
      }
      if (file.removed) {
        return false;
      }
      return (
        <Badge 
          key={file.index}
          rightSection={removeButton(file.index)}
          variant="light" 
          color="blue" 
          size="lg" 
          className="cursor-pointer pl-1 pr-1"
          leftSection={file.displayname.toLowerCase().includes(".doc")
            ? <IconFileWord className='size-5' /> 
            : file.displayname.toLowerCase().includes(".pdf") 
              ? <IconFileTypePdf className='size-5' />
              : [".jpg", ".jpeg", ".png", ".gif", ".webp", ".svg"].some(ext => file.displayname.toLowerCase().endsWith(ext))
                ? <Image src={file.path} alt={file.displayname} className='size-5' radius='xl' />
                : <IconFile className='size-5' />
          }
          >
          <div onClick={() => setDownloadFile(file)} className="flex items-center gap-1">
            <Text tt="none">{file.displayname}</Text>
          </div>
        </Badge>
      )
    })
    const existingWithoutEmpties = existingPreviews.filter(badge => badge)


    setPreviews([...existingWithoutEmpties, ...newWithoutEmpties]);
  }, [fileData]);


  /***************
   * Handle Filenames
   * *************/
  // Another side effect of fileData changing during upload process.
  useEffect(() => {
    // Do not proceed with empty.
    if (!fileData.length) {
      return
    }
    // Generate new filenames.
    //const onlyCompleted = fileData.filter(file => file.completed && !file.removed)
    const filenames = fileData.map((file, index) => {
      //console.log(file)
      let action = null
      if (file.existing) {
        action = file.removed ? "REMOVED" : "EXISTING"
      } else {
        action = file.completed && !file.removed ? "NEW" : null
      }
      return action ? action + '::' + file.key : null
    });
    const withoutEmpties = filenames.filter(instruct => instruct)

    //setState({ [inputName]: withoutEmpties.join(',') } as unknown as Form);
    setState(withoutEmpties.join(','))

  }, [fileData]);

  // Check if we should show image preview (showPreview=true, maxFiles=1, and there's an image)
  const shouldShowImagePreview = showPreview && maxFiles === 1;
  const imageFile = fileData.find(file => {
    if (file.removed || !file.completed) return false;
    const isImage = [".jpg", ".jpeg", ".png", ".gif", ".webp", ".svg"].some(ext => 
      file.displayname.toLowerCase().endsWith(ext)
    );
    return isImage;
  });

  // Build image URL for preview
  const getImageUrl = (file: FileData) => {
    const filename = file.serverfilename || file.key;
    if (!filename) return '';
    return getConfig().wwwroot + '/local/announcements2/upload.php?view=1&fileid=' + encodeURIComponent(filename) + '&sesskey=' + getConfig().sesskey;
  };

  // If showing image preview and we have an image file, show the preview instead of dropzone
  if (shouldShowImagePreview && imageFile) {
    const imageUrl = imageFile.existing ? imageFile.path : getImageUrl(imageFile);
    
    return (
      <div style={{ position: 'relative', width: '100%' }}>
        <img 
          src={imageUrl} 
          alt={imageFile.displayname}
          style={{ 
            width: '100%', 
            height: 'auto', 
            display: 'block',
            maxHeight: '500px',
            objectFit: 'contain'
          }}
        />
        {!readOnly && (
          <>
            <ActionIcon
              variant="filled"
              color="red"
              size="sm"
              onClick={(e) => {
                e.stopPropagation();
                handleRemove(imageFile.index);
              }}
              style={{
                position: 'absolute',
                top: '8px',
                left: '8px',
                zIndex: 10,
              }}
            >
              <IconX size={20} />
            </ActionIcon>
            {/* Invisible dropzone overlay for replacing the image */}
            <Dropzone
              accept={[IMAGE_MIME_TYPE].flat()}
              onDrop={(files) => {
                // Remove existing file and add new one
                setError('');
                
                // Remove the existing file from server if needed
                if (!imageFile.existing && imageFile.completed && imageFile.serverfilename) {
                  const url = getConfig().wwwroot + '/local/announcements2/upload.php?remove=1&fileid=' + imageFile.serverfilename + "&sesskey=" + getConfig().sesskey;
                  const xhr = new XMLHttpRequest();
                  xhr.open("GET", url);
                  xhr.send();
                }
                
                // Process the new file
                const newFile = files[0];
                const newFileData: FileData = {
                  index: imageFile.index, // Reuse the same index
                  displayname: newFile.name,
                  file: newFile,
                  progress: 0,
                  started: false,
                  completed: false,
                  removed: false,
                  serverfilename: '',
                  existing: false,
                  key: '',
                  path: '',
                  fileid: '',
                  mimetype: newFile.type || '',
                };
                
                // Replace the file in fileData
                setFileData(prev => prev.map(f => f.index === imageFile.index ? newFileData : f));
              }}
              onReject={(files) => {
                setError(
                  files.map(
                    file => file.errors.map(error => {
                      return error.message.includes('File type must be') ? 'File type must be an image' : error.message
                    })
                    .join(". ")
                  )
                  .filter((item, i, allItems) => {
                    return i === allItems.indexOf(item);
                  })
                  .join(". ")
                );
              }}
              maxSize={maxSize * 1024 ** 2}
              maxFiles={1}
              openRef={openRef}
              activateOnClick={true}
              styles={{ 
                root: { 
                  position: 'absolute', 
                  top: 0, 
                  left: 0, 
                  right: 0, 
                  bottom: 0,
                  backgroundColor: 'transparent',
                  border: 'none',
                },
                inner: { pointerEvents: 'all', height: '100%' }
              }}
              className="cursor-pointer"
              p={0}
              disabled={readOnly}
            >
              <div style={{ width: '100%', height: '100%' }} />
            </Dropzone>
          </>
        )}
        {error && (
          <Text 
            mt="xs" 
            c="red" 
            className="break-all" 
            style={{ 
              position: 'absolute', 
              bottom: '8px', 
              left: '8px', 
              right: '8px', 
              background: 'rgba(255, 255, 255, 0.9)', 
              padding: '4px 8px', 
              borderRadius: '4px',
              zIndex: 11,
            }}
          >
            {error}
          </Text>
        )}
      </div>
    );
  }

  return (
    <>
      <Dropzone
        accept={mimeTypes ? mimeTypes.flat() : [IMAGE_MIME_TYPE, PDF_MIME_TYPE, MS_WORD_MIME_TYPE, MS_EXCEL_MIME_TYPE, MS_POWERPOINT_MIME_TYPE].flat()}
        onDrop={handleDrop}
        onReject={(files) => {
          setError(
            files.map(
              file => file.errors.map(error => {
                return error.message.includes('File type must be') ? 'File type must be image, pdf, word, excel, or powerpoint' : error.message
              })
              .join(". ")
            )
            .filter((item, i, allItems) => {
              return i === allItems.indexOf(item);
            })
            .join(". ")
          );
        }}
        maxSize={maxSize * 1024 ** 2}
        maxFiles={maxFiles}
        openRef={openRef}
        activateOnClick={false}
        styles={{ inner: { pointerEvents: 'all' } }}
        className="cursor-default bg-white border-none rounded-none"
        p={0}
        disabled={readOnly}
      >
        <div className="px-4 pt-6 pb-2" onClick={() => openRef.current?.()}>
          <Flex className="justify-center">
            <Dropzone.Accept>
              <IconUpload
                size="2.2rem"
                stroke={1.5}
                className="text-black"
              />
            </Dropzone.Accept>
            <Dropzone.Reject>
              <IconX
                size="2.2rem"
                stroke={1.5}
                className="text-red-500"
              />
            </Dropzone.Reject>
            <Dropzone.Idle>
              <div></div>
            </Dropzone.Idle>
          </Flex>
          <div className="flex flex-col gap-4 items-start">
            <Button variant="primary" size="compact-md" radius="xl" onClick={() => openRef.current?.()}>{label ? label : 'Select file'}{maxFiles > 1 ? 's' : ''}</Button>
            <Text c="dimmed" >{desc}</Text>
          </div>
          <div className="flex gap-2">
            <Text fz="xs" c="dimmed">Maximum files: {maxFiles}</Text>
            <Text fz="xs" c="dimmed">Maximum file size: {maxSize}MB</Text>
          </div>
          {error && <Text mt="xs" c="red" className="break-all">{error}</Text>}
        </div>

        <Flex mt={previews.length > 0 ? 'sm' : 0} className="justify-start gap-2 flex-col px-4 pb-4">
          {previews}
        </Flex>



      </Dropzone>

      
      <Modal 
        opened={!!downloadFile} 
        onClose={() => setDownloadFile(null)} 
        withCloseButton={false}
        size="lg"
        scrollAreaComponent={ScrollArea.Autosize}
      >
        <div className="text-xl font-semibold mb-5">Do you want to download this file?</div>

        <div>
          <Text tt="none">{downloadFile?.displayname}</Text>
        </div>

        <div className="flex gap-2 justify-end">
          <a target="_blank" href={downloadFile?.path}>
            <Button radius="xl" size="sm" className="bg-tablr-blue" leftSection={<IconDownload />}>Download</Button>
          </a>
          <Button radius="xl" size="sm" color="gray" onClick={() => setDownloadFile(null)}>Close</Button>
        </div>
      </Modal>
    </>
  );
};