import { useState } from "react";
import { Box, Container, Center, Text, Loader, TextInput, Breadcrumbs, Modal, Button, Group, ActionIcon, Grid } from '@mantine/core';
import { useNavigate } from "react-router-dom";
import { Link as RouterLink } from 'react-router-dom';
import { Header } from "../../components/Header";
import { Footer } from "../../components/Footer";
import { getConfig } from "../../utils";
import useFetch from "../../hooks/useFetch";
import { FileUploader } from "../../components/FileUploader";
import { Announcement, Row } from "../../types/types";
import { Editor } from "../../components/Editor";
import { PageBuilder } from "../../components/PageBuilder";
import { IconEye } from "@tabler/icons-react";
import { PagePreview } from "../../components/PagePreview";




// Generate unique ID
const generateId = () => `id-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;

export function PostBuilder() {
  const api = useFetch();
  const [error, setError] = useState("")
  const [data, setData] = useState<Announcement>({
    id: 0,
    username: "",
    subject: "",
    message: "",
    timecreated: "",
    timemodified: "",
    timestart: "",
    timeend: "",
    deleted: false,
    forcesend: false,
    attachments: "",
    existingattachments: [],
    uploadedimages: "",
  })
  
  // Initialize page builder with a single row and single block
  const [pageBuilderRows, setPageBuilderRows] = useState<Row[]>([
    {
      id: generateId(),
      blocks: [{
        id: generateId(),
        type: 'text',
        content: '',
      }],
    }
  ])
  const [preview, setPreview] = useState(false)
  const navigate = useNavigate()
  document.title = 'Post Announcement'

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError("")

    // Serialize page builder rows to JSON string for storage in message field
    const serializedRows = JSON.stringify(pageBuilderRows);
    const updatedData = { ...data, message: serializedRows };

    console.log(updatedData)
    return

    let formData = JSON.parse(JSON.stringify(updatedData))


    const response = await api.call({
      method: "POST",
      body: {
        methodname: 'local_announcements2-post_announcement',
        args: formData,
      }
    })

    if (response.error) {
      setError(response.exception?.message ?? "Error submitting form")
      return
    }

    // On success, redirect to new ID if created.
    navigate('/' + response.data.id, {replace: true})
  }


  const updateField = (field: keyof Announcement, value: any) => {
    setData((state: Announcement) => ({
      ...state,
      [field]: value
    }))
  }

  const updateAttachments = (value: string) => {
    updateField('attachments', value)
  }

  // Only staff should be able to post/edit announcements
  if (!getConfig().roles?.includes("staff")) {
    return ""
  }

  return (
    <>
      <Header />
      <div style={{minHeight: 'calc(100vh - 154px)'}}>

        { error.length 
          ? <Container size="xl">
              <Center h={300}>
                <Text fw={600} fz="lg">Failed to post announcement...</Text>
                <Text c="red" mt="md">{error}</Text>
              </Center>
            </Container> 
          : <>
              <Container size="xl">
                <div className="page-header">
                  <Container size="xl" my="md" className="relative p-0 ps-7">
                      <Breadcrumbs fz="sm" mb="sm">
                        <RouterLink to="/">
                          <Text c="blue">Announcements</Text>
                        </RouterLink>
                        <Text c="gray.6">New</Text>
                      </Breadcrumbs>
                  </Container>
                </div>
              </Container>
              <Container size="xl" my="md">
                <form noValidate onSubmit={handleSubmit}>

                <Grid grow>
                <Grid.Col span={{ base: 12, lg: 9 }}>
                  <Box className="flex flex-col gap-4 relative ps-7 pe-7">

                
                    <div>
                      <Text fz="sm" fw={500} c="#212529" mb="xs">Subject</Text>
                      <TextInput
                        value={data.subject}
                        onChange={(e) => setData({...data, subject: e.target.value})}
                      />
                    </div>

                    <div>
                      <Group justify="space-between" className="mb-1">
                        <Text fz="sm" fw={500} c="#212529">Message</Text>
                        <Button variant="subtle" size="compact-sm" leftSection={<IconEye size={14} />} onClick={() => setPreview(true)}>
                          Preview
                        </Button>
                      </Group>
                      <PageBuilder 
                        rows={pageBuilderRows} 
                        onChange={setPageBuilderRows}
                      />
                    </div>

                    <div>
                      <Text fz="sm" fw={500} c="#212529">Attachments</Text>
                      <FileUploader 
                        desc={`Drag and drop files here...`} 
                        maxFiles={5} 
                        maxSize={10} 
                        existingfiles={[]} 
                        setState={updateAttachments} 
                      />
                    </div>


                    <Button 
                    type="submit"
                    variant="primary"
                    radius="xl"
                    size="md"
                    >Post Announcement</Button>




                  </Box>
                  </Grid.Col>
                  <Grid.Col span={{ base: 12, lg: 3 }}>

                    Send immediately?
                    Availability dates...
                    Save draft / Publish buttons...
                  </Grid.Col>
                  </Grid>
                </form>
              </Container>
            </> 
        }

      </div>
      <Footer />

      {preview && (
        <Modal opened={preview} onClose={() => setPreview(false)} title="Preview" size="700px">
          <PagePreview rows={pageBuilderRows} />
        </Modal>
      )}
    </>
  );
}